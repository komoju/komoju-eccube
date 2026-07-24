<?php

namespace Plugin\Komoju\Service\Method;

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
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Eccube\Service\PurchaseFlow\PurchaseException;
use Eccube\Exception\ShoppingException;
use Plugin\Komoju\Entity\KomojuOrder;
use Plugin\Komoju\Entity\KomojuConfig;
use Plugin\Komoju\Entity\KomojuPay;
use Plugin\Komoju\Service\ConfigService;
use Plugin\Komoju\Service\LogService;
use Plugin\Komoju\Service\KomojuClientFactory;

class KomojuPayment implements PaymentMethodInterface{

    protected $eccubeConfig;
    protected $entityManager;
    protected $config_service;
    protected $log_service;
    protected $order_status_repo;
    protected $requestStack;
    protected $router;
    protected $client_factory;
    // Assigned in __construct / setOrder. Declared so PHP 8.2+ does not
    // raise the "creation of dynamic property" deprecation, which becomes
    // an error in PHP 9.
    protected $purchase_flow;
    protected $Order;
    protected $form;

    /**
     * Komoju payment constructor
     * @param EccubeConfig @eccubeConfig
     * @param EntityManagerInterface $entityManager
     * @param PurchaseFlow $shoppingPurchaseFlow
     * @param OrderStatusRepository $order_status_repo
     * @param RequestStack $requestStack
     * @param ConfigService $configService
     * @param LogService $logService
     * @param UrlGeneratorInterface $router
     * @param KomojuClientFactory $clientFactory
     */

    public function __construct(
        EccubeConfig $eccubeConfig,
        EntityManagerInterface $entityManager,
        PurchaseFlow $shoppingPurchaseFlow,
        OrderStatusRepository $order_status_repo,
        RequestStack $requestStack,
        ConfigService $configService,
        LogService $logService,
        UrlGeneratorInterface $router,
        KomojuClientFactory $clientFactory
    ){
        $this->eccubeConfig = $eccubeConfig;
        $this->entityManager = $entityManager;
        $this->purchase_flow = $shoppingPurchaseFlow;
        $this->order_status_repo = $order_status_repo;
        $this->requestStack = $requestStack;
        $this->config_service = $configService;
        $this->log_service = $logService;
        $this->router = $router;
        $this->client_factory = $clientFactory;
    }
    /**
     * @return PaymentResult
     * @throws \Eccube\Service\PurchaseFlow\PurchaseException
     */
    public function verify(){
        $result = new PaymentResult();

        // Config row missing (plugin enabled but never saved) -> friendly
        // error instead of a raw 500 (core does not wrap verify() in try/catch).
        try {
            $config_data = $this->config_service->getConfigData($this->Order);
        } catch (\Exception $e) {
            $result->setSuccess(false);
            $result->setErrors([trans('komoju_payment.shopping.payment_failed')]);
            return $result;
        }
        if(empty($config_data['secret_key'])){
            $result->setSuccess(false);
            $result->setErrors([trans('komoju_payment.shopping.payment_failed')]);
            return $result;
        }

        $selectedPayment = $this->Order->getPayment();
        $komojuPay = $this->entityManager->getRepository(KomojuPay::class)
            ->findOneBy(['Payment' => $selectedPayment]);
        if(!$komojuPay){
            $result->setSuccess(false);
            $result->setErrors([trans('komoju_payment.shopping.payment_failed')]);
            return $result;
        }

        $Payment = $this->Order->getPayment();
        $min = $Payment->getRuleMin();
        $max = $Payment->getRuleMax();
        $total = $this->Order->getPaymentTotal();

        if(null !== $min && $total < $min){
            $result->setSuccess(false);
            $result->setErrors(['komoju_payment.shopping.verify.error.payment_total.too_small']);
            return $result;
        }
        if(null !== $max && $total > $max){
            $result->setSuccess(false);
            $result->setErrors(['komoju_payment.shopping.verify.error.payment_total.too_much']);
            return $result;
        }
        $result->setSuccess(true);
        return $result;
    }

    /**
     * @return PaymentDispatcher|null
     */
    public function apply(){
        // Set order status to pending
        $OrderStatus = $this->order_status_repo->find(OrderStatus::PENDING);
        $this->Order->setOrderStatus($OrderStatus);

        // Prepare purchase flow
        $this->purchase_flow->prepare($this->Order, new PurchaseContext());

        // Config missing: prepare() already reserved stock, so roll back and
        // throw PurchaseException (the type core's checkout() catches).
        try {
            $config_data = $this->config_service->getConfigData($this->Order);
        } catch (\Exception $e) {
            $OrderStatus = $this->order_status_repo->find(OrderStatus::PROCESSING);
            $this->Order->setOrderStatus($OrderStatus);
            $this->purchase_flow->rollback($this->Order, new PurchaseContext());
            throw new ShoppingException(trans('komoju_payment.shopping.payment_failed'));
        }
        $komoju_client = $this->client_factory->create($config_data['secret_key']);

        // Close abandoned sessions from earlier attempts so their hosted-page
        // URL can't be paid again (KOMOJU allows multiple payments per order).
        $this->cancelPreviousSessions($komoju_client);

        $total_amount = $this->Order->getPaymentTotal();
        $currency_code = $this->Order->getCurrencyCode();
        if(empty($currency_code)){
            $currency_code = "JPY";
        }

        $selectedPayment = $this->Order->getPayment();
        $komojuPay = $this->entityManager->getRepository(KomojuPay::class)
            ->findOneBy(['Payment' => $selectedPayment]);
        $enabled_methods = $komojuPay ? [$komojuPay->getName()] : [];
        $locale = $this->requestStack->getCurrentRequest()->getLocale() ?: 'ja';

        $return_url = $this->router->generate('Komoju_session_return', [], UrlGeneratorInterface::ABSOLUTE_URL);
        $cancel_url = $this->router->generate('Komoju_session_cancel', [], UrlGeneratorInterface::ABSOLUTE_URL);

        $session_data = [
            'amount' => $total_amount,
            'currency' => $currency_code,
            'return_url' => $return_url,
            'cancel_url' => $cancel_url,
            'default_locale' => $locale,
            'payment_types' => $enabled_methods,
            // external_order_num goes inside payment_data and must be unique
            // per session (KOMOJU returns 422 if reused).
            'payment_data' => [
                'capture' => $config_data['capture_on'] ? 'auto' : 'manual',
                'external_order_num' => $this->generateUniqueOrderNumber($config_data),
            ],
            'metadata' => [
                'eccube_order_id' => (string)$this->Order->getId(),
            ],
        ];

        $session = $komoju_client->createSession($session_data);

        if(($komoju_client->getStatusCode() != 200 || empty($session['id'])) && $komoju_client->getLastError() === 'invalid_parameter'){
            $session_data['payment_data']['external_order_num'] = $this->generateUniqueOrderNumber($config_data);
            $session = $komoju_client->createSession($session_data);
        }

        if($komoju_client->getStatusCode() != 200 || empty($session['id'])){
            $error = $komoju_client->getLastError() ?: trans('komoju_payment.shopping.payment_failed');
            $detail = $komoju_client->getLastErrorDetail();
            $this->log_service->writeLog("createSession", $this->Order->getId(), "failed: $error" . ($detail ? " ($detail)" : ""));

            $OrderStatus = $this->order_status_repo->find(OrderStatus::PROCESSING);
            $this->Order->setOrderStatus($OrderStatus);
            $this->purchase_flow->rollback($this->Order, new PurchaseContext());

            throw new ShoppingException($error);
        }

        // Store session record
        $komoju_order = new KomojuOrder;
        $komoju_order->setOrder($this->Order);
        $komoju_order->setKomojuSessionId($session['id']);
        $komoju_order->setCreatedAt(new \DateTime());
        $this->entityManager->persist($komoju_order);
        $this->entityManager->flush();

        // Redirect to KOMOJU hosted payment page
        $session_url = $session['session_url'];

        $dispatcher = new PaymentDispatcher();
        $dispatcher->setResponse(new RedirectResponse($session_url));
        return $dispatcher;
    }
    /**
     * @return PaymentResult
     */
    public function checkout(){
        // In the sessions flow, checkout() is not called because apply() returns
        // a redirect response. The SessionReturnController handles finalization.
        // This method exists only to satisfy the PaymentMethodInterface contract.
        $result = new PaymentResult();
        $result->setSuccess(true);
        return $result;
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

    /**
     * Cancel this order's still-open KOMOJU sessions so an abandoned hosted-page
     * URL can't be paid again. Best-effort: failures never abort the new attempt.
     */
    private function cancelPreviousSessions($komoju_client){
        $priorOrders = $this->entityManager->getRepository(KomojuOrder::class)
            ->findBy(['Order' => $this->Order]);
        if(empty($priorOrders)){
            return;
        }
        foreach($priorOrders as $prior){
            $sessionId = $prior->getKomojuSessionId();
            if(empty($sessionId) || $prior->isCaptured() || $prior->getCanceledAt()){
                continue;
            }
            try {
                $komoju_client->cancelSession($sessionId);
                $prior->setCanceledAt(new \DateTime());
                $this->entityManager->persist($prior);
                $this->entityManager->flush();
            } catch (\Exception $e) {
                $this->log_service->writeLog("cancelSession", $this->Order->getId(), "failed to cancel prior session $sessionId: " . $e->getMessage());
            }
        }
    }

    /**
     * Build a unique external_order_num, appending a per-attempt suffix on
     * retries so KOMOJU never sees a reused value.
     */
    private function generateUniqueOrderNumber($config_data){
        // external_order_num must be unique per session; KOMOJU rejects reuse.
        // It need not equal the EC-CUBE order id, so always append entropy.
        // The eccube_order_id is still sent in metadata for traceability.
        $baseNumber = rtrim($this->formatOrderNumber($config_data), '-');
        return $baseNumber . '-' . substr(bin2hex(random_bytes(4)), 0, 8);
    }

    private function formatOrderNumber($config_data){
        // Fixed, non-configurable format. {order_id} is the internal EC-CUBE
        // order id, which is always present and unique per order.
        return str_replace(
            ['{order_no}', '{order_id}'],
            [(string)$this->Order->getOrderNo(), (string)$this->Order->getId()],
            KomojuConfig::DEFAULT_ORDER_NUMBER_FORMAT
        );
    }
}
