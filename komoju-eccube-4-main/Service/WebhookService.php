<?php

namespace Plugin\Komoju\Service;

use Doctrine\ORM\EntityManagerInterface;
use Eccube\Repository\PaymentRepository;
use Eccube\Entity\Payment;
use Eccube\Entity\PaymentOption;
use Eccube\Common\EccubeConfig;
use Plugin\Komoju\Entity\KomojuPay;
use Plugin\Komoju\Entity\KomojuConfig;
use Plugin\Komoju\Entity\KomojuLog;
use Plugin\Komoju\Service\Method\KomojuPayment;
use Plugin\Komoju\Repository\KomojuOrderRepository;
use Plugin\Komoju\Entity\KomojuOrder;
use Plugin\Komoju\Service\LogService;
use Eccube\Entity\Master\OrderStatus;
use Eccube\Service\OrderStateMachine;
use Eccube\Entity\ProductStock;
use Eccube\Entity\Order;
class WebhookService{
    protected $entityManager;
    protected $log_service;
    protected $komoju_order_repo;
    protected $order_state_machine;
    protected $productStockRepository;


    public function __construct(
        EntityManagerInterface $entityManager,
        OrderStateMachine $orderStateMachine,
        LogService $logService
        ){
        $this->entityManager = $entityManager;
        $this->komoju_order_repo = $this->entityManager->getRepository(KomojuOrder::class);
        $this->log_service = $logService;
        $this->order_state_machine = $orderStateMachine;
        $this->productStockRepository = $this->entityManager->getRepository(ProductStock::class);
    }
    public function paymentRefunded($object){
        $komoju_payment_id = $object->data->id;
        $refunds = $object->data->refunds;
        if(empty($refunds)){
            $this->log_service->writeLog("webhook[refund]", 0, "no refunds in payload for payment: $komoju_payment_id");
            return;
        }

        // Look up order by komoju_payment_id (works for both EC-CUBE and dashboard refunds)
        $komoju_order = $this->komoju_order_repo->findOneBy(['komoju_payment_id' => $komoju_payment_id]);
        if(empty($komoju_order)){
            $this->log_service->writeLog("webhook[refund]", 0, "no order found for payment: $komoju_payment_id");
            return;
        }

        $Order = $komoju_order->getOrder();
        if(empty($Order)){
            $this->log_service->writeLog("webhook[refund]", 0, "no EC-CUBE order for payment: $komoju_payment_id");
            return;
        }

        // Calculate total refund amount and collect refund IDs from payload
        $refund_ids = [];
        $refund_amount = 0;
        foreach($refunds as $refund){
            $refund_ids[] = $refund->id;
            $refund_amount += $refund->amount;
        }

        // Check if the refunded amount has changed (detects new refunds)
        $previousAmount = (float) $komoju_order->getRefundedAmount();

        // Update KomojuOrder with refund data
        $komoju_order->setRefundId(implode(",", $refund_ids));
        $komoju_order->setRefundedAmount($refund_amount);
        $this->entityManager->persist($komoju_order);

        // Only log if the refund amount changed (avoids duplicates from admin-initiated refunds)
        if($refund_amount > $previousAmount){
            $newRefundAmount = $refund_amount - $previousAmount;
            $this->log_service->writeLog("webhook[refund]", $Order->getId(), "refund confirmed (amount=$newRefundAmount)", true);
        }

        // Only cancel order if fully refunded
        if($refund_amount >= $Order->getPaymentTotal()){
            $OrderStatus = $this->entityManager->getRepository(OrderStatus::class)->find(OrderStatus::CANCEL);
            if ($this->order_state_machine->can($Order, $OrderStatus)) {
                $this->order_state_machine->apply($Order, $OrderStatus);
            }
        }
        $this->entityManager->flush();
    }
    public function paymentAuthorized($object){
        $komoju_payment_id = $object->data->id;
        $komoju_order = $this->findKomojuOrder($object);
        if(empty($komoju_order)){
            $this->log_service->writeLog("webhook[authorized]", 0, "no order found for payment: $komoju_payment_id");
            return;
        }

        // Populate payment ID if not already set (customer didn't return via session_return)
        if(empty($komoju_order->getKomojuPaymentId())){
            $komoju_order->setKomojuPaymentId($komoju_payment_id);
            if(isset($object->data->payment_details->type)){
                $komoju_order->setType($object->data->payment_details->type);
            }
            $this->entityManager->persist($komoju_order);
        }

        $order = $komoju_order->getOrder();
        if(empty($order)){
            $this->log_service->writeLog("webhook[authorized]", 0, "no EC-CUBE order for payment: $komoju_payment_id");
            return;
        }

        $this->log_service->writeLog("webhook[authorized]", $order->getId(), "payment authorized", true);

        // If order is still in pending/processing state, move to NEW so it appears in admin
        $currentStatus = $order->getOrderStatus()->getId();
        if(in_array($currentStatus, [OrderStatus::PENDING, OrderStatus::PROCESSING])){
            $OrderStatus = $this->entityManager->find(OrderStatus::class, OrderStatus::NEW);
            $order->setOrderStatus($OrderStatus);
            $this->entityManager->persist($order);
        }
        $this->entityManager->flush();
    }

    public function paymentCaptured($object){
        $komoju_payment_id = $object->data->id;
        $komoju_order = $this->findKomojuOrder($object);
        if(empty($komoju_order)){
            $this->log_service->writeLog("webhook[captured]", 0, "no order found for payment: $komoju_payment_id");
            return;
        }
        if($komoju_order->isCaptured()){
            return;
        }

        // Populate payment ID if not already set
        if(empty($komoju_order->getKomojuPaymentId())){
            $komoju_order->setKomojuPaymentId($komoju_payment_id);
            if(isset($object->data->payment_details->type)){
                $komoju_order->setType($object->data->payment_details->type);
            }
        }

        $captured_at = new \DateTime($object->data->captured_at);
        $komoju_order->setCapturedAt($captured_at);
        $this->entityManager->persist($komoju_order);
        $this->entityManager->flush();

        $order = $komoju_order->getOrder();
        if(empty($order)){
            $this->log_service->writeLog("webhook[captured]", 0, "no EC-CUBE order for payment: $komoju_payment_id");
            return ;
        }
        $this->log_service->writeLog("webhook[captured]", $order->getId(), "payment captured", true);
        $order->setPaymentDate($captured_at);
        $OrderStatus = $this->entityManager->getRepository(OrderStatus::class)->find(OrderStatus::PAID);
        $order->setOrderStatus($OrderStatus);
        $this->entityManager->persist($order);
        $this->entityManager->flush($order);
    }
    public function paymentCanceled($object){ $this->handleCancelEvent($object, 'canceled', 'payment cancelled'); }
    public function paymentExpired($object){ $this->handleCancelEvent($object, 'expired', 'payment expired'); }
    public function paymentFailed($object){ $this->handleCancelEvent($object, 'failed', 'payment failed'); }
    private function handleCancelEvent($object, $tag, $message){
        $komoju_payment_id = $object->data->id;
        $komoju_order = $this->komoju_order_repo->findOneBy(['komoju_payment_id' => $komoju_payment_id]);
        if(empty($komoju_order)){
            $this->log_service->writeLog("webhook[$tag]", 0, "no order found for payment: $komoju_payment_id");
            return;
        }
        $order = $komoju_order->getOrder();
        if(empty($order)){
            $this->log_service->writeLog("webhook[$tag]", 0, "no EC-CUBE order for payment: $komoju_payment_id");
            return;
        }
        $this->log_service->writeLog("webhook[$tag]", $order->getId(), $message, true);
        $this->cancelOrder($komoju_order);
    }
    public function paymentUpdated($object){
        $komoju_payment_id = $object->data->id;
        $komoju_order = $this->komoju_order_repo->findOneBy(['komoju_payment_id' => $komoju_payment_id]);
        if(empty($komoju_order)){
            return;
        }
        $status = $object->data->status;
        if(in_array($status, ["expired", "cancelled"])){
            $order = $komoju_order->getOrder();
            if($order){
                $this->log_service->writeLog("webhook[updated]", $order->getId(), "payment status changed to $status", true);
            }
            $this->cancelOrder($komoju_order);
        }
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
            return;
        }

        if ($Order->getOrderStatus()->getId() == OrderStatus::CANCEL) {
            return;
        }
        $OrderStatus = $this->entityManager->find(OrderStatus::class, OrderStatus::CANCEL);
        if ($this->order_state_machine->can($Order, $OrderStatus)) {
            $this->order_state_machine->apply($Order, $OrderStatus);

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

    /**
     * Find a KomojuOrder by payment ID, or fall back to metadata.eccube_order_id.
     * This handles cases where the customer didn't return via session_return
     * (e.g., konbini instructions page with no redirect back to EC-CUBE).
     */
    private function findKomojuOrder($object){
        $komoju_payment_id = $object->data->id;

        // Try direct lookup by payment ID
        $komoju_order = $this->komoju_order_repo->findOneBy(['komoju_payment_id' => $komoju_payment_id]);
        if($komoju_order){
            return $komoju_order;
        }

        // Fall back: look up by eccube_order_id from payment metadata
        if(isset($object->data->metadata->eccube_order_id)){
            $eccube_order_id = $object->data->metadata->eccube_order_id;
            $order = $this->entityManager->getRepository(Order::class)->find($eccube_order_id);
            if($order){
                // Find the most recent KomojuOrder for this EC-CUBE order
                $komoju_order = $this->komoju_order_repo->findOneBy(
                    ['Order' => $order],
                    ['id' => 'DESC']
                );
                return $komoju_order;
            }
        }

        // Fall back: look up by session_id from payment.session
        if(isset($object->data->session)){
            $session_id = $object->data->session;
            $komoju_order = $this->komoju_order_repo->findOneBy(['komoju_session_id' => $session_id]);
            if($komoju_order){
                return $komoju_order;
            }
        }

        return null;
    }
}
