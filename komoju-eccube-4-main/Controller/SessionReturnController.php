<?php
namespace Plugin\Komoju\Controller;

use Eccube\Controller\AbstractController;
use Eccube\Entity\Master\OrderStatus;
use Eccube\Service\PurchaseFlow\PurchaseContext;
use Eccube\Service\PurchaseFlow\PurchaseFlow;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Component\HttpFoundation\Request;
use Doctrine\ORM\EntityManagerInterface;
use Plugin\Komoju\Entity\KomojuOrder;
use Plugin\Komoju\Service\ConfigService;
use Plugin\Komoju\Service\LogService;
use Plugin\Komoju\Service\KomojuClientFactory;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Eccube\Service\CartService;

class SessionReturnController extends AbstractController
{
    protected $entityManager;
    protected $config_service;
    protected $log_service;
    protected $purchase_flow;
    protected $session;
    protected $cartService;
    protected $client_factory;

    public function __construct(
        EntityManagerInterface $entityManager,
        ConfigService $configService,
        LogService $logService,
        PurchaseFlow $shoppingPurchaseFlow,
        SessionInterface $session,
        CartService $cartService,
        KomojuClientFactory $clientFactory
    ){
        $this->entityManager = $entityManager;
        $this->config_service = $configService;
        $this->log_service = $logService;
        $this->purchase_flow = $shoppingPurchaseFlow;
        $this->session = $session;
        $this->cartService = $cartService;
        $this->client_factory = $clientFactory;
    }

    /**
     * @Route("/plugin/Komoju/session/return", name="Komoju_session_return")
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

        $config_data = $this->config_service->getConfigData($Order);
        $komoju_client = $this->client_factory->create($config_data['secret_key']);
        $session = $komoju_client->getSession($session_id);

        if($komoju_client->getStatusCode() != 200 || empty($session)){
            $this->log_service->writeLog("sessionReturn", $Order->getId(), "failed to fetch session from KOMOJU API");

            // The customer may already have paid. Don't leave the order PENDING
            // with the cart live (double-charge risk): if a webhook finalized
            // it, go to completion; otherwise roll back to PROCESSING so it is
            // retryable as the same order.
            $this->entityManager->refresh($Order);
            $currentStatus = $Order->getOrderStatus()->getId();
            if(!in_array($currentStatus, [OrderStatus::PENDING, OrderStatus::PROCESSING])){
                $this->cartService->clear();
                $this->session->set('eccube.front.shopping.order.id', $Order->getId());
                return $this->redirectToRoute('shopping_complete');
            }
            $this->purchase_flow->rollback($Order, new PurchaseContext());
            $OrderStatus = $this->entityManager->find(OrderStatus::class, OrderStatus::PROCESSING);
            $Order->setOrderStatus($OrderStatus);
            $this->entityManager->flush();

            $this->addFlash('eccube.front.shopping.error', trans('komoju_payment.shopping.payment_failed'));
            return $this->redirectToRoute('shopping');
        }

        $session_status = $session['status'] ?? 'unknown';
        $payment_status = $session['payment']['status'] ?? 'unknown';

        // Session is completed for both auto-capture and manual capture.
        // Also accept if the payment itself is authorized or captured (handles edge cases).
        $session_ok = ($session_status === 'completed');
        $payment_ok = in_array($payment_status, ['captured', 'authorized']);

        if(!$session_ok && !$payment_ok){
            $this->log_service->writeLog("sessionReturn", $Order->getId(), "payment failed: session=$session_status, payment=$payment_status");
            $this->purchase_flow->rollback($Order, new PurchaseContext());
            $OrderStatus = $this->entityManager->find(OrderStatus::class, OrderStatus::PROCESSING);
            $Order->setOrderStatus($OrderStatus);
            $this->entityManager->flush();

            $this->addFlash('eccube.front.shopping.error', trans('komoju_payment.shopping.payment_failed'));
            return $this->redirectToRoute('shopping');
        }

        // Refresh order from DB to get latest status (webhook may have updated it)
        $this->entityManager->refresh($Order);

        // If the webhook already processed this order (status is no longer PENDING),
        // just redirect to completion without re-processing.
        $currentStatus = $Order->getOrderStatus()->getId();
        if(!in_array($currentStatus, [OrderStatus::PENDING, OrderStatus::PROCESSING])){
            $this->cartService->clear();
            $this->session->set('eccube.front.shopping.order.id', $Order->getId());
            return $this->redirectToRoute('shopping_complete');
        }

        try {
            // Extract payment info from session
            if(!empty($session['payment'])){
                $payment = $session['payment'];
                $komoju_order->setKomojuPaymentId($payment['id']);

                if($payment_status === 'captured'){
                    $komoju_order->setCapturedAt(new \DateTime());
                }
                if(isset($payment['payment_details']['type'])){
                    $komoju_order->setType($payment['payment_details']['type']);
                }
            }

            $this->entityManager->persist($komoju_order);
            $this->flushWithRetry();

            // Commit the purchase
            $this->purchase_flow->commit($Order, new PurchaseContext());

            // Update order status based on payment state
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
            $this->session->remove('cart_keys');
            $this->session->remove('cart_key');
        }

        // Set the order ID in session so shopping_complete can find it
        $this->session->set('eccube.front.shopping.order.id', $Order->getId());

        return $this->redirectToRoute('shopping_complete');
    }

    /**
     * @Route("/plugin/Komoju/session/cancel", name="Komoju_session_cancel")
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

    private function flushWithRetry($maxRetries = 3){
        for ($i = 0; $i < $maxRetries; $i++) {
            try {
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
