<?php

namespace Plugin\komoju42\Service;

use Psr\Container\ContainerInterface;
use Eccube\Repository\PaymentRepository;
use Eccube\Entity\Payment;
use Eccube\Entity\PaymentOption;
use Eccube\Common\EccubeConfig;
use Plugin\komoju42\Entity\KomojuPay;
use Plugin\komoju42\Entity\KomojuConfig;
use Plugin\komoju42\Entity\KomojuLog;
use Plugin\komoju42\Service\Method\KomojuMultiPay;
use Plugin\komoju42\Repository\KomojuOrderRepository;
use Plugin\komoju42\Entity\KomojuOrder;
use Eccube\Entity\Master\OrderStatus;
use Eccube\Service\OrderStateMachine;
use Eccube\Entity\ProductStock;
use Eccube\Entity\Order;
class WebhookService{
    protected $container;    
    protected $entityManager;
    protected $log_service;
    protected $komoju_order_repo;
    protected $order_state_machine;
    protected $productStockRepository;


    public function __construct(
        ContainerInterface $container,
        OrderStateMachine $orderStateMachine
        ){
        $this->container = $container;
        $this->entityManager = $container->get('doctrine.orm.entity_manager');
        $this->komoju_order_repo = $this->entityManager->getRepository(KomojuOrder::class);
        $this->log_service = $container->get("plg_komoju.service.komoju_log");
        $this->order_state_machine = $orderStateMachine;
        $this->productStockRepository = $this->entityManager->getRepository(ProductStock::class);
    }
    public function paymentRefunded($object){
        $refunds = $object->data->refunds;
        if(empty($refunds)){
            $this->log_service->writeLog("webhook[refund]", 0, "no refunds found in webhook payload for payment: {$object->data->id}");
            return;
        }
        $refund_id = $refunds[0]->id;

        $qb = $this->komoju_order_repo->createQueryBuilder("ko");
        $komoju_orders = $qb->where($qb->expr()->like("ko.refund_id", ":refund_id"))
            ->setParameter("refund_id", "%$refund_id%")
            ->getQuery()
            ->getResult();
        if(empty($komoju_orders)){
            $this->log_service->writeLog("webhook[refund]", 0, "no matching order found for refund_id: $refund_id");
            return;
        }
        $komoju_order = $komoju_orders[0];
        $Order = $komoju_order->getOrder();
        if($Order){
            $this->log_service->writeLog("webhook[refund]", $Order->getId(), "refund successfully");
            $OrderStatus = $this->entityManager->getRepository(OrderStatus::class)->find(OrderStatus::CANCEL);
            if ($this->order_state_machine->can($Order, $OrderStatus)) {
                $this->order_state_machine->apply($Order, $OrderStatus);
            }
            $this->entityManager->flush();
        }
    }
    public function paymentCaptured($object){
        $komoju_payment_id = $object->data->id;
        $komoju_order = $this->komoju_order_repo->findOneBy(['komoju_payment_id' => $komoju_payment_id]);
        if(empty($komoju_order)){
            $this->log_service->writeLog("webhook[captured]", 0, "no komoju_order found for payment: $komoju_payment_id");
            return;
        }
        if($komoju_order->isCaptured()){
            return;
        }
        $captured_at = new \DateTime($object->data->captured_at);
        $komoju_order->setCapturedAt($captured_at);
        $this->entityManager->persist($komoju_order);
        $this->entityManager->flush();

        $order = $komoju_order->getOrder();
        if(empty($order)){
            $this->log_service->writeLog("webhook[captured]", 0, "no EC-CUBE order linked to komoju_order for payment: $komoju_payment_id");
            return ;
        }        
        $order->setPaymentDate($captured_at);
        $OrderStatus = $this->entityManager->getRepository(OrderStatus::class)->find(OrderStatus::PAID);
        $order->setOrderStatus($OrderStatus);
        $this->entityManager->persist($order);
        $this->entityManager->flush($order);
    }
    public function paymentCanceled($object){
        $komoju_payment_id = $object->data->id;
        $komoju_order = $this->komoju_order_repo->findOneBy(['komoju_payment_id' => $komoju_payment_id]);
        if(empty($komoju_order)){
            $this->log_service->writeLog("webhook[canceled]", 0, "no komoju_order found for payment: $komoju_payment_id");
            return;
        }
        $order = $komoju_order->getOrder();
        if(empty($order)){
            $this->log_service->writeLog("webhook[canceled]", 0, "no EC-CUBE order linked to komoju_order for payment: $komoju_payment_id");
            return;
        }
        $this->cancelOrder($komoju_order);
    }
    public function paymentExpired($object){
        $komoju_payment_id = $object->data->id;
        $komoju_order = $this->komoju_order_repo->findOneBy(['komoju_payment_id' => $komoju_payment_id]);
        if(empty($komoju_order)){
            $this->log_service->writeLog("webhook[expired]", 0, "no komoju_order found for payment: $komoju_payment_id");
            return;
        }
        $order = $komoju_order->getOrder();
        if(empty($order)){
            $this->log_service->writeLog("webhook[expired]", 0, "no EC-CUBE order linked to komoju_order for payment: $komoju_payment_id");
            return;
        }
        $this->cancelOrder($komoju_order);
    }
    public function paymentFailed($object){
        $komoju_payment_id = $object->data->id;
        $komoju_order = $this->komoju_order_repo->findOneBy(['komoju_payment_id' => $komoju_payment_id]);
        if(empty($komoju_order)){
            $this->log_service->writeLog("webhook[failed]", 0, "no komoju_order found for payment: $komoju_payment_id");
            return;
        }
        $order = $komoju_order->getOrder();
        if(empty($order)){
            $this->log_service->writeLog("webhook[failed]", 0, "no EC-CUBE order linked to komoju_order for payment: $komoju_payment_id");
            return;
        }
        $this->cancelOrder($komoju_order);
    }
    public function paymentUpdated($object){
        $komoju_payment_id = $object->data->id;
        $komoju_order = $this->komoju_order_repo->findOneBy(['komoju_payment_id' => $komoju_payment_id]);
        if(empty($komoju_order)){
            $this->log_service->writeLog("webhook[updated]", 0, "no komoju_order found for payment: $komoju_payment_id");
            return;
        }
        $status = $object->data->status;
        if(in_array($status, ["expired", "cancelled"])){
            $this->cancelOrder($komoju_order);
        }
    }
    private function setOrderStatus($order, $status){
        $OrderStatus = $this->entityManager->getRepository(OrderStatus::class)->find($status);
        $order->setOrderStatus($OrderStatus);
        $this->entityManager->persist($order);
        $this->entityManager->flush($order);
    }
    private function cancelOrder($komoju_order){
        if($komoju_order->getCanceledAt()){
            return;
        }
        $komoju_order->setCanceledAt(new \DateTime());
        $this->entityManager->persist($komoju_order);
        $this->entityManager->flush($komoju_order);

        $Order = $komoju_order->getOrder();
        if(empty($Order)){
            $this->log_service->writeLog("webhook[cancel]", 0, "no EC-CUBE order linked to komoju_order for payment: {$komoju_order->getKomojuPaymentId()}");
            return;
        }

        if ($Order->getOrderStatus()->getId() == OrderStatus::CANCEL) {
            return;
        }
        $OrderStatus = $this->entityManager->find(OrderStatus::class, OrderStatus::CANCEL);
        if ($this->order_state_machine->can($Order, $OrderStatus)) {
            if ($OrderStatus->getId() == OrderStatus::DELIVERED) {
                
                $allShipped = true;
                foreach ($Order->getShippings() as $Ship) {
                    if (!$Ship->isShipped()) {
                        $allShipped = false;
                        break;
                    }
                }
                if ($allShipped) {
                    $this->order_state_machine->apply($Order, $OrderStatus);
                }
            } else {
                $this->order_state_machine->apply($Order, $OrderStatus);
            }

            foreach ($Order->getOrderItems() as $OrderItem) {
                $ProductClass = $OrderItem->getProductClass();
                if ($OrderItem->isProduct() && !$ProductClass->isStockUnlimited()) {
                    $this->entityManager->flush($ProductClass);
                    $ProductStock = $this->productStockRepository->findOneBy(['ProductClass' => $ProductClass]);
                    $this->entityManager->flush($ProductStock);
                }
            }
            $this->entityManager->flush($Order);

            // 会員の場合、購入回数、購入金額などを更新
            if ($Customer = $Order->getCustomer()) {
                $this->entityManager->getRepository(Order::class)->updateOrderSummary($Customer);
                $this->entityManager->flush($Customer);
            }
        }
    }

}