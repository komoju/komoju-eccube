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
use Symfony\Component\HttpFoundation\RequestStack;
use Eccube\Service\CartService;

class SessionReturnController extends AbstractController
{
    /** KOMOJU may already hold the customer's money; never roll these back. */
    const MONEY_TAKEN_PAYMENT_STATUSES = ['captured', 'authorized'];

    /** No money was taken; releasing stock and points is safe. */
    const TERMINAL_PAYMENT_STATUSES = ['failed', 'cancelled', 'canceled', 'expired'];
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
            $this->addFlash('eccube.front.shopping.error', trans('komoju_payment.shopping.payment_failed'));
            return $this->redirectToRoute('shopping');
        }

        $komoju_order = $this->entityManager->getRepository(KomojuOrder::class)
            ->findOneBy(['komoju_session_id' => $session_id]);

        if(empty($komoju_order)){
            $this->log_service->writeLog("sessionReturn", 0, "no komoju_order found for session: $session_id");
            $this->addFlash('eccube.front.shopping.error', trans('komoju_payment.shopping.payment_failed'));
            return $this->redirectToRoute('shopping');
        }

        $Order = $komoju_order->getOrder();
        if(empty($Order)){
            $this->log_service->writeLog("sessionReturn", 0, "no EC-CUBE order for session: $session_id");
            $this->addFlash('eccube.front.shopping.error', trans('komoju_payment.shopping.payment_failed'));
            return $this->redirectToRoute('shopping');
        }

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
            try {
                $this->entityManager->refresh($Order);
            } catch (\Throwable $refreshError) {
                $this->log_service->writeLog("sessionReturn", $Order->getId(), "failed to refresh order after session error: " . $refreshError->getMessage());
            }
            $currentStatus = $Order->getOrderStatus()->getId();
            if(!in_array($currentStatus, [OrderStatus::PENDING, OrderStatus::PROCESSING])){
                try {
                    $this->cartService->clear();
                } catch (\Throwable $cartError) {
                    $this->requestStack->getSession()->remove('cart_keys');
                    $this->requestStack->getSession()->remove('cart_key');
                }
                $this->requestStack->getSession()->set('eccube.front.shopping.order.id', $Order->getId());
                return $this->redirectToRoute('shopping_complete');
            }
            $this->addFlash('eccube.front.shopping.error', trans('komoju_payment.shopping.payment_pending'));
            return $this->redirectToRoute('shopping');
        }

        if($komoju_client->getStatusCode() != 200 || empty($session)){
            $this->log_service->writeLog("sessionReturn", $Order->getId(), "failed to fetch session from KOMOJU API");

            // The session lookup failed, but the customer may already have paid.
            // We must NOT roll back here: if the payment actually succeeded, the
            // webhook (authoritative) will finalize the order, and a rollback
            // would restore stock the paid order still owns -> oversell.
            $this->entityManager->refresh($Order);
            $currentStatus = $Order->getOrderStatus()->getId();
            if(!in_array($currentStatus, [OrderStatus::PENDING, OrderStatus::PROCESSING])){
                // A webhook already finalized it -> go to completion.
                $this->cartService->clear();
                $this->requestStack->getSession()->set('eccube.front.shopping.order.id', $Order->getId());
                return $this->redirectToRoute('shopping_complete');
            }
            // Outcome unknown: leave the order PENDING for the webhook to resolve
            // and tell the customer we're confirming their payment.
            $this->addFlash('eccube.front.shopping.error', trans('komoju_payment.shopping.payment_pending'));
            return $this->redirectToRoute('shopping');
        }

        $session_status = $session['status'] ?? 'unknown';
        $payment_status = $session['payment']['status'] ?? 'unknown';

        // Three-way outcome. Rolling back restores stock and reverses points, so
        // it is only safe when KOMOJU says definitively that no money was taken.
        //   money taken  -> finalize (session envelope may lag/expire on a late return)
        //   terminal     -> roll back
        //   otherwise    -> indeterminate; leave PENDING for the authoritative webhook
        $payment_ok = in_array($payment_status, self::MONEY_TAKEN_PAYMENT_STATUSES);
        $payment_terminal = in_array($payment_status, self::TERMINAL_PAYMENT_STATUSES);
        $session_terminal = in_array($session_status, self::TERMINAL_SESSION_STATUSES);

        if(!$payment_ok){
            if($payment_terminal || $session_terminal){
                $this->log_service->writeLog("sessionReturn", $Order->getId(), "payment failed: session=$session_status, payment=$payment_status");
                $this->purchase_flow->rollback($Order, new PurchaseContext());
                $OrderStatus = $this->entityManager->find(OrderStatus::class, OrderStatus::PROCESSING);
                $Order->setOrderStatus($OrderStatus);
                $this->entityManager->flush();

                $this->addFlash('eccube.front.shopping.error', trans('komoju_payment.shopping.payment_failed'));
                return $this->redirectToRoute('shopping');
            }

            $this->log_service->writeLog("sessionReturn", $Order->getId(), "payment not settled yet: session=$session_status, payment=$payment_status");
            $this->addFlash('eccube.front.shopping.error', trans('komoju_payment.shopping.payment_pending'));
            return $this->redirectToRoute('shopping');
        }

        // Refresh order from DB to get latest status (webhook may have updated it)
        $this->entityManager->refresh($Order);

        // If the webhook already processed this order (status is no longer PENDING),
        // just redirect to completion without re-processing.
        $currentStatus = $Order->getOrderStatus()->getId();
        if(!in_array($currentStatus, [OrderStatus::PENDING, OrderStatus::PROCESSING])){
            $this->cartService->clear();
            $this->requestStack->getSession()->set('eccube.front.shopping.order.id', $Order->getId());
            return $this->redirectToRoute('shopping_complete');
        }

        try {
            // Extract payment info from session
            if(!empty($session['payment'])){
                $payment = $session['payment'];
                $komoju_order->setKomojuPaymentId($payment['id']);

                if($payment_status === 'captured'){
                    $komoju_order->setCapturedAt(new \DateTime());
                    if(isset($payment['amount'])){
                        $komoju_order->setCapturedAmount((int)$payment['amount']);
                    }
                }
                if(isset($payment['payment_details']['type'])){
                    $komoju_order->setType($payment['payment_details']['type']);
                }
            }

            $this->entityManager->persist($komoju_order);
            $this->flushWithRetry();

            // Atomically claim finalization so a concurrent webhook cannot also
            // run commit() and double-count buy stats/points. Only the winner
            // of the status compare-and-swap commits the purchase flow.
            $targetStatus = $payment_status === 'captured' ? OrderStatus::PAID : OrderStatus::NEW;
            if($this->claimFinalization($Order, $targetStatus)){
                // Commit the purchase
                $this->purchase_flow->commit($Order, new PurchaseContext());

                if($payment_status === 'captured'){
                    $Order->setPaymentDate(new \DateTime());
                    $OrderStatus = $this->entityManager->find(OrderStatus::class, OrderStatus::PAID);
                    $Order->setOrderStatus($OrderStatus);
                } else {
                    // For authorized payments (konbini, bank transfer, etc.)
                    // set to NEW so the order appears in the admin order list
                    $OrderStatus = $this->entityManager->find(OrderStatus::class, OrderStatus::NEW);
                    $Order->setOrderStatus($OrderStatus);
                }
                $this->entityManager->flush();
            }

            if($payment_status === 'captured'){
                $this->log_service->writeLog("sessionReturn", $Order->getId(), "purchase completed (payment=captured)", true);
            } else {
                $this->log_service->writeLog("sessionReturn", $Order->getId(), "order accepted (awaiting payment)", true);
            }
        } catch (\Exception $e) {
            // (a) EM closed / DB busy: the webhook finalized this order
            //     concurrently; safe to fall through to completion.
            // (b) Genuine failure (e.g. PurchaseException from a stock race in
            //     commit()): order NOT finalized, so roll back and send the
            //     customer to checkout rather than a false success page.
            // A closed EM is the reliable signal for (a).
            $emClosed = !$this->entityManager->isOpen()
                || $e instanceof \Doctrine\ORM\Exception\ORMException
                || $e instanceof \Doctrine\DBAL\Exception;

            if($emClosed){
                $this->log_service->writeLog("sessionReturn", $Order->getId(), "finalize skipped (EM/DB busy, likely webhook race): " . $e->getMessage());
            } else {
                $this->log_service->writeLog("sessionReturn", $Order->getId(), "finalize failed: " . $e->getMessage());
                try {
                    $this->purchase_flow->rollback($Order, new PurchaseContext());
                    $OrderStatus = $this->entityManager->find(OrderStatus::class, OrderStatus::PROCESSING);
                    $Order->setOrderStatus($OrderStatus);
                    $this->entityManager->flush();
                } catch (\Exception $inner) {
                    $this->log_service->writeLog("sessionReturn", $Order->getId(), "rollback after finalize failure also failed: " . $inner->getMessage());
                }
                $this->addFlash('eccube.front.shopping.error', trans('komoju_payment.shopping.payment_failed'));
                return $this->redirectToRoute('shopping');
            }
        }

        // Clear the cart
        try {
            $this->cartService->clear();
        } catch (\Exception $e) {
            // EntityManager may be closed from webhook race condition.
            // Clear session cart keys so the customer doesn't see stale cart.
            $this->requestStack->getSession()->remove('cart_keys');
            $this->requestStack->getSession()->remove('cart_key');
        }

        // Set the order ID in session so shopping_complete can find it
        $this->requestStack->getSession()->set('eccube.front.shopping.order.id', $Order->getId());

        return $this->redirectToRoute('shopping_complete');
    }

    /**
     * @Route("/plugin/Komoju42/session/cancel", name="Komoju42_session_cancel")
     */
    public function sessionCancel(Request $request){
        $session_id = $request->query->get('session_id');

        if(!empty($session_id)){
            $komoju_order = $this->entityManager->getRepository(KomojuOrder::class)
                ->findOneBy(['komoju_session_id' => $session_id]);

            if($komoju_order){
                $Order = $komoju_order->getOrder();
                if($Order){
                    // Only roll back an in-progress order. Rolling back a
                    // captured/authorized order (e.g. browser back/forward
                    // hitting cancel_url) would reverse stock/points on a paid
                    // order.
                    $currentStatus = $Order->getOrderStatus()->getId();
                    if(in_array($currentStatus, [OrderStatus::PENDING, OrderStatus::PROCESSING])){
                        $this->purchase_flow->rollback($Order, new PurchaseContext());
                        $OrderStatus = $this->entityManager->find(OrderStatus::class, OrderStatus::PROCESSING);
                        $Order->setOrderStatus($OrderStatus);
                        $this->entityManager->flush();
                        $this->log_service->writeLog("sessionCancel", $Order->getId(), "customer cancelled payment", true);
                    } else {
                        $this->log_service->writeLog("sessionCancel", $Order->getId(), "cancel ignored; order already finalized (status=$currentStatus)", true);
                    }
                }
            }
        }

        $this->addFlash('eccube.front.shopping.error', trans('komoju_payment.shopping.payment_cancelled'));
        return $this->redirectToRoute('shopping');
    }

    /**
     * Atomically claim the right to finalize an order that is still
     * PENDING/PROCESSING, moving it to $newStatusId in a single UPDATE. Returns
     * true only for the caller that actually transitioned the row, so this
     * controller and the webhook (or duplicate deliveries) cannot both run the
     * non-idempotent purchase-flow commit. Falls back to an in-memory status
     * check in non-DB (test/stub) contexts.
     */
    private function claimFinalization($Order, $newStatusId){
        try {
            $affected = $this->entityManager->getConnection()->executeStatement(
                'UPDATE dtb_order SET order_status_id = ? WHERE id = ? AND order_status_id IN (?, ?)',
                [$newStatusId, $Order->getId(), OrderStatus::PENDING, OrderStatus::PROCESSING]
            );
            return ((int) $affected) > 0;
        } catch (\Throwable $e) {
            $currentStatus = $Order->getOrderStatus()->getId();
            return in_array($currentStatus, [OrderStatus::PENDING, OrderStatus::PROCESSING]);
        }
    }

    private function flushWithRetry($maxRetries = 3){
        for ($i = 0; $i < $maxRetries; $i++) {
            try {
                if (!$this->entityManager->isOpen()) {
                    // EntityManager was closed by a previous error (e.g., webhook race condition).
                    // The webhook likely already processed this order successfully.
                    return;
                }
                $this->entityManager->flush();
                return;
            } catch (\Doctrine\DBAL\Exception\LockWaitTimeoutException $e) {
                if ($i === $maxRetries - 1) {
                    throw $e;
                }
                usleep(500000); // 500ms
            }
        }
    }
}
