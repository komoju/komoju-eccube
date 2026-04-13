<?php

namespace Plugin\Komoju42;

use Eccube\Common\EccubeConfig;
use Eccube\Event\TemplateEvent;
use Eccube\Entity\BaseInfo;
use Eccube\Entity\Payment;
use Eccube\Entity\Master\OrderStatus;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Doctrine\ORM\EntityManagerInterface;
use Plugin\Komoju42\Service\Method\KomojuMultiPay;
use Plugin\Komoju42\Service\ConfigService;
use Plugin\Komoju42\Entity\KomojuOrder;
use Plugin\Komoju42\Entity\KomojuLog;
use Eccube\Event\EventArgs;

class KomojuEvent implements EventSubscriberInterface{

    private $entityManager;
    private $errorMessage;
    private $eccubeConfig;
    private $config_service;
    private $router;
    private $base_info;

    const EVENT_KOMOJU_CONFIG_LOAD = "PLUGIN.KOMOJU.CONFIG.LOAD";

    public function __construct(
        EccubeConfig $eccubeConfig,
        EntityManagerInterface $entityManager,
        ConfigService $configService,
        UrlGeneratorInterface $router
    ){
        $this->eccubeConfig = $eccubeConfig;
        $this->entityManager = $entityManager;
        $this->config_service = $configService;
        $this->router = $router;
        $this->base_info = $this->entityManager->getRepository(BaseInfo::class)->get();
    }
    /**
     * @return array
     */
    public static function getSubscribedEvents(){
        return [
            'Shopping/index.twig'   =>  'onShoppingIndexTwig',
            'front.shopping.complete.initialize'    =>  'onFrontShoppingCompleteInitialize',
            '@admin/Order/index.twig'   =>  'onAdminOrderIndexTwig',
            '@admin/Order/edit.twig'    =>  'onAdminOrderEditTwig',
        ];
    }

    /**
     * @param TemplateEvent
     */
    public function onShoppingIndexTwig(TemplateEvent $event){
        // Each KOMOJU method is now its own Payment entity, so EC-CUBE natively
        // renders them as individual radio buttons. No snippet injection needed.
    }

    /**
     * @param EventArgs $event
     */
    public function onFrontShoppingCompleteInitialize(EventArgs $event){
        $Order=$event->getArgument('Order');
        if($Order) {
            if ($Order->getPayment()->getMethodClass() === KomojuMultiPay::class) {
                $komoju_order_repo = $this->entityManager->getRepository(KomojuOrder::class);
                $komoju_order = $komoju_order_repo->findOneBy(array('Order'=>$Order));
                if($komoju_order) {
                    $payment_id = $komoju_order->getKomojuPaymentId();
                    if (!empty($payment_id) && $komoju_order->isCaptured()) {
                        $Today = new \DateTime();
                        $Order->setPaymentDate($Today);
                        $OrderStatus = $this->entityManager->getRepository(OrderStatus::class)->find(OrderStatus::PAID);
                        $Order->setOrderStatus($OrderStatus);
                        $this->entityManager->persist($Order);
                        $this->entityManager->flush($Order);
                    }
                }
            }
        }
    }

    /**
     * @param TemplateEvent $event
     */
    public function onAdminOrderIndexTwig(TemplateEvent $event){
        $pagination = $event->getParameter("pagination");
        if (empty($pagination) || count($pagination) == 0)
        {
            return;
        }

        $OrderToSearch=array();
        foreach ($pagination as $Order){
            $OrderToSearch[] = $Order;
        }
        if (empty($OrderToSearch)) {
            return;
        }

        $komoju_order_repo = $this->entityManager->getRepository(KomojuOrder::class);
        $komoju_orders = $komoju_order_repo->findBy(['Order'    =>  $OrderToSearch]);

        if(empty($komoju_orders)){
            return;
        }
        $komoju_order_mapping = array();
        foreach($komoju_orders as $komoju_order){
            $Order = $komoju_order->getOrder();

            if($komoju_order->getKomojuPaymentId()){
                $dashboard_url = $this->getKomojuDashboardLink($komoju_order->getKomojuPaymentId());
            }else{
                $dashboard_url = null;
            }
            $order_edit_url = $this->router->generate('admin_order_edit', ['id' => $Order->getId()], UrlGeneratorInterface::ABSOLUTE_URL);
            $komoju_order_mapping[] = (object)['order_edit_url' => $order_edit_url, 'payment_id' => $komoju_order->getKomojuPaymentId(), 'dashboard_url' => $dashboard_url];

        }

        $event->setParameter('komoju_order_mapping', $komoju_order_mapping);
        $event->addAsset('@Komoju42/admin/order_index.js.twig');
    }

    /**
     * @param TemplateEvent
     */
    public function onAdminOrderEditTwig(TemplateEvent $event){
        $Order = $event->getParameter("Order");

        if(!$Order || empty($Order->getPayment())){
            return;
        }
        if ($Order->getPayment()->getMethodClass() === KomojuMultiPay::class) {
            $komoju_order = $this->entityManager->getRepository(KomojuOrder::class)->findOneBy(['Order' => $Order]);
            if(empty($komoju_order)
                || empty($komoju_order->getKomojuPaymentId())
                ){
                return ;
            }
            if(!$komoju_order->getIsChargeRefunded() && $komoju_order->getSelectedRefundOption() === 0 && $komoju_order->getRefundedAmount() == 0){
                $komoju_order->setRefundedAmount($Order->getPaymentTotal());
                $this->entityManager->persist($komoju_order);
                $this->entityManager->flush();
            }
            $refund_full_option = KomojuOrder::REFUND_FULL;
            $refund_partial_option = KomojuOrder::REFUND_PARTIAL;

            $order_canceled = $Order->getOrderStatus()->getId() == OrderStatus::CANCEL;

            $event->setParameter("komoju_order", $komoju_order);
            $event->setParameter("order_canceled", $order_canceled);
            $event->setParameter("komoju_dashboard_link", $this->getKomojuDashboardLink($komoju_order->getKomojuPaymentId()));
            $event->setParameter('REFUND_FULL_OPTION',  $refund_full_option);
            $event->setParameter('REFUND_PARTIAL_OPTION',  $refund_partial_option);

            $komoju_logs = $this->entityManager->getRepository(KomojuLog::class)
                ->createQueryBuilder('l')
                ->where('l.order_id = :order_id')
                ->setParameter('order_id', $Order->getId())
                ->orderBy('l.id', 'ASC')
                ->getQuery()
                ->getResult();
            $event->setParameter('komoju_logs', $komoju_logs);

            $event->setParameter('komoju_order_num', $this->formatOrderNumber($Order));

            $event->addSnippet("@Komoju42/admin/order_edit.twig");
        }
    }

    private function formatOrderNumber($Order){
        try {
            $config_data = $this->config_service->getConfigData($Order);
            $format = !empty($config_data['order_number_format']) ? $config_data['order_number_format'] : null;
        } catch (\Exception $e) {
            $format = null;
        }
        if (empty($format)) {
            return (string)$Order->getOrderNo();
        }
        return str_replace(
            ['{order_no}', '{order_id}'],
            [(string)$Order->getOrderNo(), (string)$Order->getId()],
            $format
        );
    }

    private function getKomojuDashboardLink($komoju_payment_id){
        return "https://app.komoju.com/merchant/payments/$komoju_payment_id";
    }
}