<?php

namespace Plugin\komoju42\Service\Method;

use Eccube\Common\EccubeConfig;
use Eccube\Entity\Master\OrderStatus;
use Eccube\Entity\Order;
use Eccube\Entity\Customer;
use Eccube\Entity\Payment;
use Eccube\Repository\Master\OrderStatusRepository;
use Eccube\Service\Payment\PaymentDispatcher;
use Eccube\Service\Payment\PaymentMethodInterface;
use Eccube\Service\Payment\PaymentResult;
use Eccube\Service\PurchaseFlow\PurchaseContext;
use Eccube\Service\PurchaseFlow\PurchaseFlow;
use Symfony\Component\Form\FormInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Plugin\komoju42\KomojuClient;
use Plugin\komoju42\Entity\KomojuOrder;
use Plugin\komoju42\Entity\KomojuPay;

class KomojuMultiPay implements PaymentMethodInterface{
    
    protected $eccubeConfig;
    protected $entityManager;
    protected $container;
    protected $config_service;
    protected $log_service;
    protected $order_status_repo;
    protected $requestStack;

    /**
     * Komoju multipay constructor
     * @param ContainerInterface $container
     */

    public function __construct(ContainerInterface $container){

        $this->container = $container;


        $this->eccubeConfig = $this->container->get('Eccube\Common\EccubeConfig');;
        $this->entityManager = $this->container->get('doctrine.orm.entity_manager');
        $this->purchase_flow = $this->container->get('eccube.purchase.flow.shopping');
        $this->order_status_repo = $this->entityManager->getRepository(OrderStatus::class);;
        $this->requestStack = $this->container->get('request_stack');
        $this->config_service = $this->container->get("plg_komoju.service.config");
        $this->log_service = $this->container->get("plg_komoju.service.komoju_log");
    }
    /**
     * @return PaymentResult
     * @throws \Eccube\Service\PurchaseFlow\PurchaseException
     */
    public function verify(){
        $result = new PaymentResult();
        $payment_repo = $this->entityManager->getRepository(Payment::class);
        $Payment = $payment_repo->findOneBy(['method_class' => KomojuMultiPay::class]);
        $min = $Payment->getRuleMin();
        $max = $Payment->getRuleMax();
        $total = $this->Order->getPaymentTotal();

        if(null !== $min && $total < $min){
            $result->setSuccess(false);
            $result->setErrors(['komoju_multipay.shopping.verify.error.payment_total.too_small']);
            return $result;
        }
        if(null !== $max && $total > $max){
            $result->setSuccess(false);
            $result->setErrors(['komoju_multipay.shopping.verify.error.payment_total.too_much']);
            return $result;
        }
        $result->setSuccess(true);
        return $result;
    }

    /**
     * @return PaymentDispatcher|null
     */
    public function apply(){
        // 受注ステータスを決済処理中へ変更
        $OrderStatus = $this->order_status_repo->find(OrderStatus::PENDING);
        $this->Order->setOrderStatus($OrderStatus);

        // purchase_flow::prepareを呼び出し, 購入処理を進める.
        $this->purchase_flow->prepare($this->Order, new PurchaseContext());
    }
    /**
     * @return PaymentResult
     */
    public function checkout(){
        log_info("KomojuMultiPay---checkout");
        $payment_token = $this->requestStack->getCurrentRequest()->request->get('komojuToken');
        $payment_type = $this->checkPaymentType();

        if(empty($payment_token) || empty($payment_type)){
            $this->log_service->writeLog("createPayment", $this->Order->getId(), "payment_token or payment_type is invalid");
            $result = new PaymentResult();
            $result->setSuccess(false);
            $result->setErrors(['komoju_multipay.shopping.payment_failed']);
            return $result;
        }

        $config_data = $this->config_service->getConfigData($this->Order);
        $komoju_client = new KomojuClient($config_data['secret_key']);

        $this->log_service->writeLog("createPayment", $this->Order->getId(), "request payments");
        $total_amount = $this->Order->getPaymentTotal();

        $currency_code = $this->Order->getCurrencyCode();
        if(empty($currency_code)){
            $currency_code = "JPY";
        }
        $komoju_payment = $komoju_client->createPayment([
            'amount'    =>  $total_amount,
            'tax'       =>  0,
            'currency'  =>  $currency_code,
            'payment_details'=> $payment_token,
            'capture'   =>  $config_data['capture_on'],
            'fraud_details'=>[
                'customer_ip' => $this->requestStack->getCurrentRequest()->getClientIp(),
                'customer_email'=> $this->Order->getEmail(),
            ],
            'return_url'=> $this->container->get('router')->generate('shopping_complete', [], UrlGeneratorInterface::ABSOLUTE_URL),
        ]);
        
        $this->log_service->writeLog("createPayment", $this->Order->getId(), "response with status_code: {$komoju_client->getStatusCode()}");
        if($komoju_client->getStatusCode() != 200){
            log_info("KomojuMultiPay----");            
            $error = $komoju_client->getLastError();
            if($komoju_client->getStatusCode() == 202){
                $error = trans("komoju_multipay.shopping.not_enough_error");
            }
            if(isset($komoju_payment['id'])){
                $komoju_client->cancelPayment($komoju_payment['id']);
            }
            $this->log_service->writeLog("createPayment", $this->Order->getId(), "response msg : {$error}");
            $OrderStatus = $this->order_status_repo->find(OrderStatus::PROCESSING);
            $this->Order->setOrderStatus($OrderStatus);

            // 失敗時はpurchaseFlow::commitを呼び出す.
            $this->purchase_flow->rollback($this->Order, new PurchaseContext());

            $result = new PaymentResult();
            $result->setSuccess(false);
            $result->setErrors( [$error] );
            return $result;
        }else{
            $this->log_service->writeLog("createPayment", $this->Order->getId(), "success");
            $this->log_service->writeLog("createPayment", $this->Order->getId(), "status : " . $komoju_payment['status']);
            $komoju_order = new KomojuOrder;
            $komoju_order->setOrder($this->Order);
            $komoju_order->setPaymentToken($payment_token);
            $komoju_order->setKomojuPaymentId($komoju_payment['id']);
            $komoju_order->setCreatedAt(new \DateTime());
            if(isset($komoju_payment['status']) && $komoju_payment['status'] === "captured"){
                $komoju_order->setCapturedAt(new \DateTime());
            }
            $komoju_order->setType($payment_type);
            $this->entityManager->persist($komoju_order);
            $this->entityManager->flush();
    
            $this->purchase_flow->commit($this->Order, new PurchaseContext());
            $result = new PaymentResult();
            $result->setSuccess(true);
            return $result;
        }

    }
    private function checkPaymentType(){
        $type = $this->requestStack->getCurrentRequest()->request->get('payment_type');
        $enabled_methods = $this->entityManager->getRepository(KomojuPay::class)->getEnabledMethodsString();
        if(in_array($type, $enabled_methods)){
            return $type;
        }
        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function setFormType(FormInterface $form){
        $this->form = $form;
    }

    /**
     * {@inheritdoc}
     */
    public function setOrder(Order $order){
        $this->Order = $order;
    }
}