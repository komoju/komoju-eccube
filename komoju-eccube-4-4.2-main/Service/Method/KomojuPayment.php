<?php

namespace Plugin\Komoju42\Service\Method;

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
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Eccube\Service\PurchaseFlow\PurchaseException;
use Plugin\Komoju42\Entity\KomojuOrder;
use Plugin\Komoju42\Entity\KomojuPay;
use Plugin\Komoju42\Entity\KomojuConfig;
use Plugin\Komoju42\Service\ConfigService;
use Plugin\Komoju42\Service\LogService;
use Plugin\Komoju42\Service\KomojuClientFactory;

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
     * @param EccubeConfig $eccubeConfig
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

        $config_data = $this->config_service->getConfigData($this->Order);
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

        $config_data = $this->config_service->getConfigData($this->Order);
        $komoju_client = $this->client_factory->create($config_data['secret_key']);

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

        $return_url = $this->router->generate('Komoju42_session_return', [], UrlGeneratorInterface::ABSOLUTE_URL);
        $cancel_url = $this->router->generate('Komoju42_session_cancel', [], UrlGeneratorInterface::ABSOLUTE_URL);

        $session_data = [
            'amount' => $total_amount,
            'currency' => $currency_code,
            'return_url' => $return_url,
            'cancel_url' => $cancel_url,
            'default_locale' => $locale,
            'payment_types' => $enabled_methods,
            // external_order_num belongs INSIDE payment_data per KOMOJU's
            // sessions API (payment_data[external_order_num]). It must be
            // UNIQUE per session — KOMOJU returns 422 "Payment data is invalid"
            // if the value was already used — so generateUniqueOrderNumber()
            // appends a unique suffix on retries.
            'payment_data' => [
                'capture' => $config_data['capture_on'] ? 'auto' : 'manual',
                'external_order_num' => $this->generateUniqueOrderNumber($config_data),
            ],
            'metadata' => [
                'eccube_order_id' => (string)$this->Order->getId(),
            ],
        ];

        $session = $komoju_client->createSession($session_data);

        if($komoju_client->getStatusCode() != 200 || empty($session['id'])){
            $error = $komoju_client->getLastError() ?: trans('komoju_payment.shopping.payment_failed');
            $this->log_service->writeLog("createSession", $this->Order->getId(), "failed: $error");

            $OrderStatus = $this->order_status_repo->find(OrderStatus::PROCESSING);
            $this->Order->setOrderStatus($OrderStatus);
            $this->purchase_flow->rollback($this->Order, new PurchaseContext());

            throw new PurchaseException($error);
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
     * Generate a unique external_order_num for KOMOJU.
     *
     * KOMOJU requires external_order_num to be unique per session and returns
     * 422 "Payment data is invalid" if a value is reused. A customer who
     * abandons a payment and retries (or any prior failed attempt) would
     * otherwise collide on the same order number. The previous implementation
     * derived the suffix from a count of KomojuOrder rows, which is not
     * collision-proof (failed attempts may not advance the count predictably,
     * and a re-submit of the same order can reuse a value KOMOJU already saw).
     *
     * We always append a short unique token on retries so each attempt sends a
     * value KOMOJU has not seen, while keeping the readable base order number
     * on the first attempt.
     */
    private function generateUniqueOrderNumber($config_data){
        $baseNumber = $this->formatOrderNumber($config_data);

        // Count existing KOMOJU sessions for this order to detect retries.
        $existingCount = $this->entityManager->getRepository(KomojuOrder::class)
            ->count(['Order' => $this->Order]);

        if ($existingCount > 0) {
            // Append a unique-per-attempt suffix (retry index + short random
            // token) so a reused order number can never collide on KOMOJU.
            $suffix = ($existingCount + 1) . '-' . substr(bin2hex(random_bytes(3)), 0, 5);
            return $baseNumber . '-' . $suffix;
        }

        return $baseNumber;
    }

    private function formatOrderNumber($config_data){
        // external_order_num MUST be unique per session (KOMOJU returns 422
        // invalid_parameter otherwise). We use a FIXED format that always
        // includes the EC-CUBE internal order id, which is guaranteed present
        // and unique once the order is persisted. This is intentionally not
        // merchant-configurable: a custom format (or an empty {order_no}) could
        // produce a non-unique value shared across orders, causing the second
        // order onward to fail. {order_no} is kept for human readability in
        // KOMOJU settlement reports; {order_id} guarantees uniqueness.
        return str_replace(
            ['{order_no}', '{order_id}'],
            [(string)$this->Order->getOrderNo(), (string)$this->Order->getId()],
            KomojuConfig::DEFAULT_ORDER_NUMBER_FORMAT
        );
    }
}
