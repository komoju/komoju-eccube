<?php

namespace Plugin\Komoju\Controller\Admin;

use Eccube\Controller\AbstractController;
use Eccube\Entity\Master\OrderStatus;
use Eccube\Entity\Order;
use Eccube\Repository\OrderRepository;
use Eccube\Repository\Master\OrderStatusRepository;
use Plugin\Komoju\Repository\KomojuOrderRepository;
use Plugin\Komoju\KomojuClient;
use Plugin\Komoju\Entity\KomojuOrder;
use Plugin\Komoju\Entity\KomojuPay;
use Plugin\Komoju\Service\ConfigService;
use Plugin\Komoju\Service\LogService;
use Plugin\Komoju\Service\MailExService;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Doctrine\DBAL\LockMode;

class OrderController extends AbstractController{

    protected $order_repo;
    protected $config_service;
    protected $komoju_order_repo;
    protected $log_service;
    protected $order_status_repo;
    protected $mail_ex_service;

    public function __construct(
        OrderRepository $order_repo,
        OrderStatusRepository $order_status_repo,
        KomojuOrderRepository $komoju_order_repo,
        ConfigService $configService,
        LogService $logService,
        MailExService $mailExService
    ){
        $this->order_repo = $order_repo;
        $this->order_status_repo = $order_status_repo;
        $this->komoju_order_repo = $komoju_order_repo;
        $this->config_service = $configService;
        $this->log_service = $logService;
        $this->mail_ex_service = $mailExService;
    }
    /**
     * @Route("/%eccube_admin_route%/Komoju/payment/{id}/capture_transaction", requirements={"id" = "\d+"}, name="Komoju_capture_transaction", methods={"POST"})
     */
    public function charge(Request $request, $id){
        if (!$this->isCsrfTokenValid('Komoju_capture_' . $id, $request->request->get('_token'))) {
            $this->addError('komoju_multipay.admin.order.error.invalid_request', 'admin');
            return $this->redirectToRoute('admin_order');
        }

        $Order = $this->order_repo->find($id);
        if(empty($Order)){
            $this->addError('komoju_multipay.admin.order.error.invalid_request', 'admin');
            return $this->redirectToRoute('admin_order');
        }
        $config = $this->config_service->getConfigData($Order);
        $komoju_order = $this->komoju_order_repo->findOneBy(['Order'    =>  $Order]);
        if(empty($komoju_order)){
            $this->addError('komoju_multipay.admin.order.error.invalid_request', 'admin');
            return $this->redirectToRoute('admin_order');
        }

        if($komoju_order->getIsChargeRefunded()){
            $this->addError('komoju_multipay.admin.order.error.refunded', 'admin');
            return $this->redirectToRoute('admin_order');
        }

        if($komoju_order->isCaptured()){
            $this->addError('komoju_multipay.admin.order.error.already_captured', 'admin');
            return $this->redirectToRoute('admin_order');
        }
        $komoju_client = new KomojuClient($config['secret_key']);
        $payment_obj = $komoju_client->getPayment($komoju_order->getKomojuPaymentId());
        $this->log_service->writeLog("retrieve", $Order->getId(), "result status_code : " . $komoju_client->getStatusCode());
        if($komoju_client->getStatusCode() != 200 || empty($payment_obj)){
            $this->addError($komoju_client->getLastError(), 'admin');
            $this->log_service->writeLog("retrieve", $Order->getId(), "retrieve failed, code=" . $komoju_client->getStatusCode());
            return $this->redirectToRoute('admin_order_edit', ['id' => $Order->getId()]);
        }

        if($payment_obj['status'] === "captured"){
            $komoju_order->setCapturedAt(new \DateTime($payment_obj['captured_at']));
            $this->entityManager->persist($komoju_order);
            $this->entityManager->flush();
            $this->setOrderStatus($Order, OrderStatus::PAID);
            $this->addError('komoju_multipay.admin.order.error.already_captured', 'admin');
            return $this->redirectToRoute('admin_order_edit', ['id' => $Order->getId()]);
        }

        $this->log_service->writeLog("capture", $Order->getId(), "capture start");
        $payment_obj = $komoju_client->capturePayment($komoju_order->getKomojuPaymentId());
        if($komoju_client->getStatusCode() != 200 || empty($payment_obj)){
            $this->addError($komoju_client->getLastError(), 'admin');
            $this->log_service->writeLog("capture", $Order->getId(), "capture failed : " . $komoju_client->getLastError());
            return $this->redirectToRoute('admin_order_edit', ['id' => $Order->getId()]);
        }

        if(isset($payment_obj['status']) && $payment_obj['status'] != "captured"){
            $this->addError('komoju_multipay.admin.order.error.capture_failed', 'admin');
            return $this->redirectToRoute('admin_order_edit', ['id' => $Order->getId()]);
        }

        $komoju_order->setCapturedAt(new \DateTime($payment_obj['captured_at']));
        $this->entityManager->persist($komoju_order);
        $this->entityManager->flush();
        $this->setOrderStatus($Order, OrderStatus::PAID);
        $this->addSuccess('komoju_multipay.admin.order.capture_success', 'admin');
        return $this->redirectToRoute('admin_order_edit', ['id' =>  $Order->getId()]);
    }


    /**
     * @Route("/%eccube_admin_route%/Komoju/payment/{id}/refund_transaction", requirements={"id" = "\d+"}, name="Komoju_refund_transaction")
     */
    public function refund(Request $request, $id = null){
        $Order = $this->order_repo->find($id);

        if(empty($Order)){
            $this->addError('komoju_multipay.admin.order.error.invalid_request', 'admin');
            return $this->redirectToRoute('admin_order');
        }
        $config = $this->config_service->getConfigData($Order);
        $this->log_service->writeLog("refund", $Order->getId(), "refund requested by admin");
        if($request->getMethod() == "POST"){
            if (!$this->isCsrfTokenValid('Komoju_refund_' . $id, $request->request->get('_token'))) {
                $this->addError('komoju_multipay.admin.order.error.invalid_request', 'admin');
                return $this->redirectToRoute('admin_order');
            }

            $komoju_order = $this->komoju_order_repo->findOneBy(['Order'    =>  $Order]);

            if(empty($komoju_order) || empty($komoju_order->getKomojuPaymentId())){
                $this->log_service->writeLog("refund", $Order->getId(), "refund invalid request, komoju order is empty");
                $this->addError('komoju_multipay.admin.order.error.invalid_request', 'admin');
                return $this->redirectToRoute('admin_order');
            }

            // Lock the row to prevent concurrent refund attempts
            $this->entityManager->lock($komoju_order, LockMode::PESSIMISTIC_WRITE);

            // check if already refunded
            if ($komoju_order->getIsChargeRefunded()) {
                $this->log_service->writeLog("refund", $Order->getId(), "refund invalid request, komoju order is already refunded");
                $this->addError('komoju_multipay.admin.order.error.refunded', 'admin');
                return $this->redirectToRoute('admin_order_edit', ['id' => $Order->getId()]);
            }

            $komoju_client = new KomojuClient($config['secret_key']);
            $this->log_service->writeLog("retrieve", $Order->getId(), "retrieve payment to check whether already refunded");


            $payment_obj = $komoju_client->getPayment($komoju_order->getKomojuPaymentId());
            $this->log_service->writeLog("retrieve", $Order->getId(), "result status_code : " . $komoju_client->getStatusCode());
            if($komoju_client->getStatusCode() != 200 || empty($payment_obj)){
                $this->addError($komoju_client->getLastError(), 'admin');
                $this->log_service->writeLog("retrieve", $Order->getId(), "retrieve failed");
                return $this->redirectToRoute('admin_order_edit', ['id' => $Order->getId()]);
            }
            $refunds = isset($payment_obj['refunds']) ? $payment_obj['refunds'] : null;
            if(!empty($refunds) && count($refunds) > 0){
                $refund_ids = [];
                $refund_amount = 0;
                foreach($refunds as $refund){
                    $refund_amount += $refund['amount'];
                    $refund_ids[] = $refund['id'];
                }
                $komoju_order->setRefundId(implode(",", $refund_ids));
                $komoju_order->setSelectedRefundOption(KomojuOrder::REFUND_UNKNOWN);
                $komoju_order->setRefundedAmount($refund_amount);
                $this->entityManager->persist($komoju_order);
                $this->entityManager->flush();

                $OrderStatus = $this->order_status_repo->find(OrderStatus::CANCEL);
                $Order->setOrderStatus($OrderStatus);
                $this->entityManager->persist($Order);
                $this->entityManager->flush($Order);

                $this->addError('komoju_multipay.admin.order.error.refunded', 'admin');
                $this->log_service->writeLog("refund", $Order->getId(), "already refunded");
                return $this->redirectToRoute('admin_order_edit', ['id' => $Order->getId()]);
            }

            $refund_option = $request->request->get('refund_option');
            $refund_amount = 0;

            if((int)$refund_option === KomojuOrder::REFUND_FULL){
                $refund_amount = floor($Order->getPaymentTotal());
            }else if((int)$refund_option === KomojuOrder::REFUND_PARTIAL){
                $refund_amount = $request->request->get('refund_amount');
                $refund_amount = (int)$refund_amount;
                if ($refund_amount <= 0) {
                    $this->addError('komoju_multipay.admin.order.refund_amount.error.invalid', 'admin');
                    return $this->redirectToRoute('admin_order_edit', ['id' => $Order->getId()]);
                } else if($refund_amount>$Order->getPaymentTotal()){
                    $this->addError('komoju_multipay.admin.order.refund_amount.error.exceeded', 'admin');
                    return $this->redirectToRoute('admin_order_edit', ['id' => $Order->getId()]);
                }
            }else{
                $this->addError('komoju_multipay.admin.order.error.refund_option.invalid', 'admin');
                return $this->redirectToRoute('admin_order_edit', ['id' => $Order->getId()]);
            }
            $this->log_service->writeLog("refund", $Order->getId(), "refund option = $refund_option, refund_amount = $refund_amount");

            $payment_obj = $komoju_client->refundPayment($komoju_order->getKomojuPaymentId(), ['amount' => $refund_amount]);
            if($komoju_client->getStatusCode() != 200){
                $this->log_service->writeLog("refund", $Order->getId(), "code : " . $komoju_client->getStatusCode() . " ,error : " . $komoju_client->getLastError());
                $this->addError($komoju_client->getLastError(), 'admin');
                return $this->redirectToRoute('admin_order_edit', ['id' => $Order->getId()]);
            }
            if($payment_obj && isset($payment_obj["refunds"]) && count($payment_obj["refunds"])){
                $refund = $payment_obj["refunds"][0];
                $this->log_service->writeLog("refund", $Order->getId(), \json_encode($refund));
                $this->log_service->writeLog("refund", $Order->getId(), "refund api call success");

                $komoju_order->setRefundId($refund['id']);
                $komoju_order->setSelectedRefundOption($refund_option);
                $komoju_order->setRefundedAmount($refund_amount);
                $this->entityManager->persist($komoju_order);
                $this->entityManager->flush();

                $OrderStatus = $this->order_status_repo->find(OrderStatus::CANCEL);
                $Order->setOrderStatus($OrderStatus);
                $this->entityManager->persist($Order);
                $this->entityManager->flush($Order);

                if(isset($refund['redirect_url'])){
                    $this->mail_ex_service->sendRefundRedirectMail($Order, $refund['redirect_url']);
                }
                $this->addSuccess('komoju_multipay.admin.order.refund.success', 'admin');
                return $this->redirectToRoute('admin_order_edit', ['id' => $Order->getId()]);
            }else{
                $this->log_service->writeLog("refund", $Order->getId(), "error : refund result is empty");
                $this->addError('komoju_multipay.admin.order.error.refund_failed', 'admin');
                return $this->redirectToRoute('admin_order_edit', ['id' => $Order->getId()]);
            }
        } else {
            $this->addError('komoju_multipay.admin.order.error.invalid_request', 'admin');
            return $this->redirectToRoute('admin_order');
        }
    }
    public function setOrderStatus(Order $order, $status){
        $order->setPaymentDate(new \DateTime());
        $order_status = $this->order_status_repo->find($status);
        $order->setOrderStatus($order_status);
        $this->entityManager->persist($order);
        $this->entityManager->flush($order);
    }
}