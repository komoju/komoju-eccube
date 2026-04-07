<?php
namespace Plugin\komoju42\Controller;

use Eccube\Controller\AbstractController;
use Eccube\Entity\Master\OrderStatus;
use Eccube\Service\PurchaseFlow\PurchaseContext;
use Eccube\Service\PurchaseFlow\PurchaseFlow;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Request;
use Doctrine\ORM\EntityManagerInterface;
use Plugin\komoju42\KomojuClient;
use Plugin\komoju42\Entity\KomojuOrder;
use Plugin\komoju42\Service\ConfigService;
use Plugin\komoju42\Service\LogService;

class SessionReturnController extends AbstractController
{
    protected $entityManager;
    protected $config_service;
    protected $log_service;
    protected $purchase_flow;

    public function __construct(
        EntityManagerInterface $entityManager,
        ConfigService $configService,
        LogService $logService,
        PurchaseFlow $shoppingPurchaseFlow
    ){
        $this->entityManager = $entityManager;
        $this->config_service = $configService;
        $this->log_service = $logService;
        $this->purchase_flow = $shoppingPurchaseFlow;
    }

    /**
     * @Route("/plugin/komoju42/session/return", name="komoju42_session_return")
     */
    public function sessionReturn(Request $request){
        $session_id = $request->query->get('session_id');
        if(empty($session_id)){
            $this->log_service->writeLog("sessionReturn", 0, "no session_id in request");
            $this->addFlash('eccube.front.shopping.error', trans('komoju_multipay.shopping.payment_failed'));
            return $this->redirectToRoute('shopping');
        }

        $this->log_service->writeLog("sessionReturn", 0, "processing return for session: $session_id");

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

        $this->log_service->writeLog("sessionReturn", $Order->getId(), "session status: " . ($session['status'] ?? 'unknown'));

        if(!isset($session['status']) || $session['status'] !== 'completed'){
            $this->log_service->writeLog("sessionReturn", $Order->getId(), "session not completed, rolling back");
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

            if(isset($payment['status']) && $payment['status'] === 'captured'){
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

        $this->log_service->writeLog("sessionReturn", $Order->getId(), "purchase committed successfully");

        return $this->redirectToRoute('shopping_complete');
    }

    /**
     * @Route("/plugin/komoju42/session/cancel", name="komoju42_session_cancel")
     */
    public function sessionCancel(Request $request){
        $session_id = $request->query->get('session_id');
        $this->log_service->writeLog("sessionCancel", 0, "cancel for session: $session_id");

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
                    $this->log_service->writeLog("sessionCancel", $Order->getId(), "purchase rolled back");
                }
            }
        }

        $this->addFlash('eccube.front.shopping.error', trans('komoju_multipay.shopping.payment_cancelled'));
        return $this->redirectToRoute('shopping');
    }
}
