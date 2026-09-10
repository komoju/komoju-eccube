<?php
namespace Plugin\Komoju42\Controller;

use Eccube\Controller\AbstractController;
use Eccube\Entity\Master\OrderStatus;
use Eccube\Service\PurchaseFlow\PurchaseContext;
use Eccube\Service\PurchaseFlow\PurchaseFlow;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Request;
use Doctrine\ORM\EntityManagerInterface;
use Plugin\Komoju42\Entity\KomojuOrder;
use Plugin\Komoju42\Service\ConfigService;
use Plugin\Komoju42\Service\LogService;
use Plugin\Komoju42\Service\KomojuClientFactory;
use Plugin\Komoju42\Service\PaymentAttemptTransitionTrait;
use Symfony\Component\HttpFoundation\RequestStack;
use Eccube\Service\CartService;

class SessionReturnController extends AbstractController
{
    use PaymentAttemptTransitionTrait;

    /** KOMOJU may already hold the customer's money; never roll these back. */
    const MONEY_TAKEN_PAYMENT_STATUSES = ['captured', 'authorized'];
    const TERMINAL_PAYMENT_STATUSES = ['cancelled', 'canceled', 'expired'];

    const TERMINAL_SESSION_STATUSES = ['failed', 'cancelled', 'canceled'];

    protected $entityManager;
    protected $config_service;
    protected $log_service;
    protected $purchase_flow;
    protected $requestStack;
    protected $cartService;
    protected $client_factory;

    public function __construct(
        EntityManagerInterface $entityManager,
        ConfigService $configService,
        LogService $logService,
        PurchaseFlow $shoppingPurchaseFlow,
        RequestStack $requestStack,
        CartService $cartService,
        KomojuClientFactory $clientFactory
    ){
        $this->entityManager = $entityManager;
        $this->config_service = $configService;
        $this->log_service = $logService;
        $this->purchase_flow = $shoppingPurchaseFlow;
        $this->requestStack = $requestStack;
        $this->cartService = $cartService;
        $this->client_factory = $clientFactory;
    }


    /**
     * @Route("/plugin/Komoju42/session/return", name="Komoju42_session_return")
     */
    public function sessionReturn(Request $request){
        $session_id = $request->query->get('session_id');
        if(empty($session_id)){
            $this->log_service->writeLog("sessionReturn", 0, "no session_id in request");
            return $this->redirectToShopping('komoju_payment.shopping.payment_failed');
        }

        $komoju_order = $this->entityManager->getRepository(KomojuOrder::class)
            ->findOneBy(['komoju_session_id' => $session_id]);

        if(empty($komoju_order)){
            $this->log_service->writeLog("sessionReturn", 0, "no komoju_order found for session: $session_id");
            return $this->redirectToShopping('komoju_payment.shopping.payment_failed');
        }

        $Order = $komoju_order->getOrder();
        if(empty($Order)){
            $this->log_service->writeLog("sessionReturn", 0, "no EC-CUBE order for session: $session_id");
            return $this->redirectToShopping('komoju_payment.shopping.payment_failed');
        }
        $state = (string)$request->query->get('state');
        $stateHash = hash('sha256', $state);
        $storedStateHash = $komoju_order->getCallbackTokenHash();
        if(empty($state) || empty($storedStateHash) || !hash_equals($storedStateHash, $stateHash)){
            $this->log_service->writeLog("sessionReturn", $Order->getId(), "callback state rejected");
            return $this->redirectToShopping('komoju_payment.shopping.payment_pending');
        }

        $browserStateHash = $this->requestStack->getSession()->get('komoju.callback.' . $session_id);
        $browserAuthorized = is_string($browserStateHash) && hash_equals($storedStateHash, $browserStateHash);


        try {
            $config_data = $this->config_service->getConfigData($Order);
            if(empty($config_data['secret_key'])){
                throw new \RuntimeException('KOMOJU secret key is not configured.');
            }
            $komoju_client = $this->client_factory->create($config_data['secret_key']);
            $session = $komoju_client->getSession($session_id);
        } catch (\Throwable $e) {
            log_error($e);
            $this->log_service->writeLog("sessionReturn", $Order->getId(), "failed to load payment session: " . $e->getMessage());
            if(!$this->refreshOrder($Order)){
                return $this->redirectToShopping('komoju_payment.shopping.payment_pending');
            }
            $currentStatus = $Order->getOrderStatus()->getId();
            if(!in_array($currentStatus, [OrderStatus::PENDING, OrderStatus::PROCESSING])){
                return $this->redirectAfterCompletion($Order, $browserAuthorized, $session_id);
            }
            return $this->redirectToShopping('komoju_payment.shopping.payment_pending');
        }

        if($komoju_client->getStatusCode() != 200 || empty($session)){
            $this->log_service->writeLog("sessionReturn", $Order->getId(), "failed to fetch session from KOMOJU API");

            // The session lookup failed, but the customer may already have paid.
            // We must NOT roll back here: if the payment actually succeeded, the
            // webhook (authoritative) will finalize the order, and a rollback
            // would restore stock the paid order still owns -> oversell.
            if(!$this->refreshOrder($Order)){
                return $this->redirectToShopping('komoju_payment.shopping.payment_pending');
            }
            $currentStatus = $Order->getOrderStatus()->getId();
            if(!in_array($currentStatus, [OrderStatus::PENDING, OrderStatus::PROCESSING])){
                return $this->redirectAfterCompletion($Order, $browserAuthorized, $session_id);
            }
            // Outcome unknown: leave the order PENDING for the webhook to resolve
            // and tell the customer we're confirming their payment.
            return $this->redirectToShopping('komoju_payment.shopping.payment_pending');
        }
        if(!$this->sessionMatchesAttempt($komoju_order, $Order, $session)){
            $this->log_service->writeLog("sessionReturn", $Order->getId(), "session correlation rejected");
            return $this->redirectToShopping('komoju_payment.shopping.payment_pending');
        }


        $session_status = $session['status'] ?? 'unknown';
        $payment_status = $session['payment']['status'] ?? 'unknown';

        // A failed payment can be retried while the hosted session remains open.
        $payment_ok = in_array($payment_status, self::MONEY_TAKEN_PAYMENT_STATUSES);
        $payment_terminal = in_array($payment_status, self::TERMINAL_PAYMENT_STATUSES);
        $session_terminal = in_array($session_status, self::TERMINAL_SESSION_STATUSES);

        if(!$payment_ok){
            if($payment_terminal || $session_terminal){
                if(!$this->refreshOrder($Order)){
                    return $this->redirectToShopping('komoju_payment.shopping.payment_pending');
                }
                $currentStatus = $Order->getOrderStatus()->getId();
                if(!in_array($currentStatus, [OrderStatus::PENDING, OrderStatus::PROCESSING])){
                    if($currentStatus == OrderStatus::PAID){
                        return $this->redirectAfterCompletion($Order, $browserAuthorized, $session_id);
                    }
                    return $this->redirectToShopping('komoju_payment.shopping.payment_pending');
                }
                try {
                    $processed = $this->transactional(function () use ($komoju_order, $Order) {
                        $this->entityManager->lock($Order, \Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);
                        $this->entityManager->lock($komoju_order, \Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);
                        $this->entityManager->refresh($Order);
                        $this->entityManager->refresh($komoju_order);
                        $lockedStatus = $Order->getOrderStatus()->getId();
                        if(!in_array($lockedStatus, [OrderStatus::PENDING, OrderStatus::PROCESSING])
                            || !$this->isCurrentAttempt($komoju_order, $Order)){
                            return false;
                        }
                        if(!$this->claimCancellation($komoju_order)){
                            return false;
                        }
                        $this->purchase_flow->rollback($Order, new PurchaseContext());
                        $Order->setOrderStatus($this->entityManager->find(OrderStatus::class, OrderStatus::PROCESSING));
                        $this->entityManager->persist($komoju_order);
                        $this->entityManager->flush();
                        return true;
                    });
                } catch (\Throwable $e) {
                    $this->log_service->writeLog("sessionReturn", $Order->getId(), "cancel failed: " . $e->getMessage());
                    return $this->redirectToShopping('komoju_payment.shopping.payment_pending');
                }
                if(!$processed){
                    if(!$this->refreshOrder($Order)){
                        return $this->redirectToShopping('komoju_payment.shopping.payment_pending');
                    }
                    $currentStatus = $Order->getOrderStatus()->getId();
                    if(!in_array($currentStatus, [OrderStatus::PENDING, OrderStatus::PROCESSING])){
                        return $this->redirectAfterCompletion($Order, $browserAuthorized, $session_id);
                    }
                } else {
                    $this->log_service->writeLog("sessionReturn", $Order->getId(), "payment failed: session=$session_status, payment=$payment_status");
                }
                return $this->redirectToShopping('komoju_payment.shopping.payment_failed');
            }

            $this->log_service->writeLog("sessionReturn", $Order->getId(), "payment not settled yet: session=$session_status, payment=$payment_status");
            return $this->redirectToShopping('komoju_payment.shopping.payment_pending');
        }

        // Refresh order from DB to get latest status (webhook may have updated it)
        if(!$this->refreshOrder($Order)){
            return $this->redirectToShopping('komoju_payment.shopping.payment_pending');
        }

        // Purchase acceptance does not imply that capture has been recorded yet.
        $currentStatus = $Order->getOrderStatus()->getId();
        if($currentStatus == OrderStatus::CANCEL){
            return $this->redirectToShopping('komoju_payment.shopping.payment_pending');
        }
        if(!in_array($currentStatus, [OrderStatus::PENDING, OrderStatus::PROCESSING])
            && !($currentStatus == OrderStatus::NEW && $payment_status === 'captured')){
            return $this->redirectAfterCompletion($Order, $browserAuthorized, $session_id);
        }

        try {
            $processed = $this->transactional(function () use ($session, $payment_status, $komoju_order, $Order) {
                $this->entityManager->lock($Order, \Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);
                $this->entityManager->lock($komoju_order, \Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);
                $this->entityManager->refresh($Order);
                $this->entityManager->refresh($komoju_order);
                $status = $Order->getOrderStatus()->getId();
                if($status == OrderStatus::CANCEL){
                    return false;
                }

                $capturedAt = null;
                if(!empty($session['payment'])){
                    $payment = $session['payment'];
                    if(!$this->bindPaymentId($komoju_order, $payment['id'])){
                        return false;
                    }
                    if($payment_status === 'authorized' && $komoju_order->getCanceledAt()){
                        return false;
                    }
                    if($payment_status === 'captured'){
                        $capturedAt = $komoju_order->getCapturedAt() ?: (isset($payment['captured_at'])
                            ? new \DateTime($payment['captured_at'])
                            : new \DateTime());
                        $capturedAmount = isset($payment['amount']) ? (int)$payment['amount'] : null;
                        if(!$this->claimCaptured($komoju_order, $capturedAt, $capturedAmount)){
                            return false;
                        }
                    }
                    if(isset($payment['payment_details']['type'])){
                        $komoju_order->setType($payment['payment_details']['type']);
                    }
                }

                $this->entityManager->persist($komoju_order);
                if(in_array($status, [OrderStatus::PENDING, OrderStatus::PROCESSING])){
                    $targetStatus = $payment_status === 'captured' ? OrderStatus::PAID : OrderStatus::NEW;
                    if($this->claimFinalization($Order, $targetStatus)){
                        $this->purchase_flow->commit($Order, new PurchaseContext());
                    }
                }
                if($payment_status === 'captured'
                    && in_array($status, [OrderStatus::PENDING, OrderStatus::PROCESSING, OrderStatus::NEW])){
                    $Order->setPaymentDate($capturedAt ?: new \DateTime());
                    $Order->setOrderStatus($this->entityManager->find(OrderStatus::class, OrderStatus::PAID));
                }
                $this->entityManager->flush();
                return true;
            });
        } catch (\Throwable $e) {
            $this->log_service->writeLog("sessionReturn", $Order->getId(), "finalize failed: " . $e->getMessage());
            return $this->redirectToShopping('komoju_payment.shopping.payment_pending');
        }
        if(!$processed){
            $this->log_service->writeLog("sessionReturn", $Order->getId(), "payment attempt is terminal");
            return $this->redirectToShopping('komoju_payment.shopping.payment_pending');
        }
        if($payment_status === 'authorized' && !$this->refreshOrder($Order)){
            return $this->redirectToShopping('komoju_payment.shopping.payment_pending');
        }

        if($payment_status === 'captured'){
            $this->log_service->writeLog("sessionReturn", $Order->getId(), "purchase completed (payment=captured)", true);
        } else {
            $this->log_service->writeLog("sessionReturn", $Order->getId(), "order accepted (awaiting payment)", true);
        }

        return $this->redirectAfterCompletion($Order, $browserAuthorized, $session_id);
    }

    /**
     * @Route("/plugin/Komoju42/session/cancel", name="Komoju42_session_cancel")
     */
    public function sessionCancel(Request $request){
        return $this->sessionReturn($request);
    }





    private function isCurrentAttempt($attempt, $Order){
        $current = $this->entityManager->getRepository(KomojuOrder::class)
            ->findOneBy(['Order' => $Order], ['id' => 'DESC']);
        if($current === $attempt){
            return true;
        }
        return $current && $current->getId() && $current->getId() === $attempt->getId();
    }

    private function sessionMatchesAttempt($komojuOrder, $Order, array $session){
        if(isset($session['id'])
            && (string)$session['id'] !== (string)$komojuOrder->getKomojuSessionId()){
            return false;
        }
        if(!empty($komojuOrder->getKomojuPaymentId())
            && isset($session['payment']['id'])
            && !hash_equals((string)$komojuOrder->getKomojuPaymentId(), (string)$session['payment']['id'])){
            return false;
        }
        if($komojuOrder->getExpectedAmount() !== null
            && (!isset($session['amount']) || (int)$session['amount'] !== (int)$komojuOrder->getExpectedAmount())){
            return false;
        }
        if($komojuOrder->getExpectedCurrency() !== null
            && (!isset($session['currency'])
                || strtoupper((string)$session['currency']) !== strtoupper((string)$komojuOrder->getExpectedCurrency()))){
            return false;
        }
        if($komojuOrder->getExpectedAmount() !== null
            && isset($session['payment']['amount'])
            && (int)$session['payment']['amount'] !== (int)$komojuOrder->getExpectedAmount()){
            return false;
        }
        if($komojuOrder->getExpectedCurrency() !== null
            && isset($session['payment']['currency'])
            && strtoupper((string)$session['payment']['currency']) !== strtoupper((string)$komojuOrder->getExpectedCurrency())){
            return false;
        }
        if(isset($session['metadata']['eccube_order_id'])
            && (string)$session['metadata']['eccube_order_id'] !== (string)$Order->getId()){
            return false;
        }
        return true;
    }

    private function refreshOrder($Order){
        try {
            $this->entityManager->refresh($Order);
            return true;
        } catch (\Throwable $e) {
            // The database may be unavailable; avoid the database-backed payment log.
            log_error($e);
            return false;
        }
    }

    private function redirectToShopping($message){
        $this->addFlash('eccube.front.shopping.error', trans($message));
        return $this->redirectToRoute('shopping');
    }

    private function redirectAfterCompletion($Order, $browserAuthorized, $sessionId){
        if(!$browserAuthorized || !in_array($Order->getOrderStatus()->getId(), [
            OrderStatus::NEW,
            OrderStatus::PAID,
            OrderStatus::IN_PROGRESS,
            OrderStatus::DELIVERED,
        ], true)){
            return $this->redirectToShopping('komoju_payment.shopping.payment_pending');
        }
        $this->clearCheckoutCart($Order, $sessionId);
        $this->requestStack->getSession()->set('eccube.front.shopping.order.id', $Order->getId());
        return $this->redirectToRoute('shopping_complete');
    }

    private function clearCheckoutCart($Order, $sessionId){
        // Consume cleanup authority, not receipt authority, even if cleanup fails.
        $binding = $this->requestStack->getSession()->remove('komoju.callback_cart.' . $sessionId);
        if(!is_array($binding)
            || ($binding['order_id'] ?? null) !== $Order->getId()
            || empty($binding['cart_id']) || empty($binding['pre_order_id'])
            || $binding['pre_order_id'] !== $Order->getPreOrderId()){
            return;
        }
        try {
            $Cart = $this->cartService->getCart();
            if(!$Cart || $Cart->getId() !== $binding['cart_id']){
                return;
            }
            $this->transactional(function () use ($Cart, $binding) {
                $this->entityManager->lock($Cart, \Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);
                $this->entityManager->refresh($Cart);
                if($Cart->getPreOrderId() !== $binding['pre_order_id']
                    || $this->cartService->getCart() !== $Cart){
                    return;
                }
                $this->cartService->clear();
            });
        } catch (\Throwable $e) {
            // Never replace a failed cart operation with broad session cleanup.
            log_error($e);
        }
    }


}
