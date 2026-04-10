<?php
namespace Plugin\Komoju\Controller;

use Eccube\Controller\AbstractController;
use Eccube\Entity\Master\OrderStatus;
use Eccube\Service\PurchaseFlow\PurchaseContext;
use Eccube\Service\PurchaseFlow\PurchaseFlow;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Component\HttpFoundation\Request;
use Doctrine\ORM\EntityManagerInterface;
use Plugin\Komoju\KomojuClient;
use Plugin\Komoju\Entity\KomojuOrder;
use Plugin\Komoju\Service\ConfigService;
use Plugin\Komoju\Service\LogService;
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

    public function __construct(
        EntityManagerInterface $entityManager,
        ConfigService $configService,
        LogService $logService,
        PurchaseFlow $shoppingPurchaseFlow,
        SessionInterface $session,
        CartService $cartService
    ){
        $this->entityManager = $entityManager;
        $this->config_service = $configService;
        $this->log_service = $logService;
        $this->purchase_flow = $shoppingPurchaseFlow;
        $this->session = $session;
        $this->cartService = $cartService;
    }

    /**
     * @Route("/plugin/Komoju/session/return", name="Komoju_session_return")
     */
    public function sessionReturn(Request $request){
        $session_id = $request->query->get('session_id');
        if(empty($session_id)){
            $this->log_service->writeLog("sessionReturn", 0, "no session_id in request");
            $this->addFlash('eccube.front.shopping.error', trans('komoju_multipay.shopping.payment_failed'));
            return $this->redirectToRoute('shopping');
        }

        $komoju_order = $this->entityManager->getRepository(KomojuOrder::class)
            ->findOneBy(['komoju_session_id' => $session_id]);

        if(empty($komoju_order)){
            $this->log_service->writeLog("sessionReturn", 0, "no komoju_order found for session: $session_id");
            $this->addFlash('eccube.front.shopping.error', trans('komoju_multipay.shopping.payment_failed'));
            return $this->redirectToRoute('shopping');
        }

        $Order = $komoju_order->getOrder();
        if(empty($Order)){
            $this->log_service->writeLog("sessionReturn", 0, "no EC-CUBE order for session: $session_id");
            $this->addFlash('eccube.front.shopping.error', trans('komoju_multipay.shopping.payment_failed'));
            return $this->redirectToRoute('shopping');
        }

        $config_data = $this->config_service->getConfigData($Order);
        $komoju_client = new KomojuClient($config_data['secret_key']);
        $session = $komoju_client->getSession($session_id);

        if($komoju_client->getStatusCode() != 200 || empty($session)){
            $this->log_service->writeLog("sessionReturn", $Order->getId(), "failed to fetch session from KOMOJU API");
            $this->addFlash('eccube.front.shopping.error', trans('komoju_multipay.shopping.payment_failed'));
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

            $this->addFlash('eccube.front.shopping.error', trans('komoju_multipay.shopping.payment_failed'));
            return $this->redirectToRoute('shopping');
        }

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
        $this->entityManager->flush();

        // Commit the purchase
        $this->purchase_flow->commit($Order, new PurchaseContext());

        // Clear the cart
        $this->cartService->clear();

        // Set the order ID in session so shopping_complete can find it
        $this->session->set('eccube.front.shopping.order.id', $Order->getId());

        $this->log_service->writeLog("sessionReturn", $Order->getId(), "purchase completed (payment=$payment_status)");

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
                    $this->purchase_flow->rollback($Order, new PurchaseContext());
                    $OrderStatus = $this->entityManager->find(OrderStatus::class, OrderStatus::PROCESSING);
                    $Order->setOrderStatus($OrderStatus);
                    $this->entityManager->flush();
                    $this->log_service->writeLog("sessionCancel", $Order->getId(), "customer cancelled payment");
                }
            }
        }

        $this->addFlash('eccube.front.shopping.error', trans('komoju_multipay.shopping.payment_cancelled'));
        return $this->redirectToRoute('shopping');
    }
}
