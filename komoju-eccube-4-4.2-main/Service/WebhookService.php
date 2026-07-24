<?php

namespace Plugin\Komoju42\Service;

use Doctrine\ORM\EntityManagerInterface;
use Eccube\Repository\PaymentRepository;
use Eccube\Entity\Payment;
use Eccube\Entity\PaymentOption;
use Eccube\Common\EccubeConfig;
use Plugin\Komoju42\Entity\KomojuPay;
use Plugin\Komoju42\Entity\KomojuConfig;
use Plugin\Komoju42\Entity\KomojuLog;
use Plugin\Komoju42\Service\Method\KomojuPayment;
use Plugin\Komoju42\Repository\KomojuOrderRepository;
use Plugin\Komoju42\Entity\KomojuOrder;
use Plugin\Komoju42\Service\LogService;
use Eccube\Entity\Master\OrderStatus;
use Eccube\Service\OrderStateMachine;
use Eccube\Entity\Order;
class WebhookService{
    protected $entityManager;
    protected $log_service;
    protected $komoju_order_repo;
    protected $order_state_machine;
    protected $purchase_flow;


    public function __construct(
        EntityManagerInterface $entityManager,
        OrderStateMachine $orderStateMachine,
        LogService $logService,
        \Eccube\Service\PurchaseFlow\PurchaseFlow $shoppingPurchaseFlow
        ){
        $this->entityManager = $entityManager;
        $this->komoju_order_repo = $this->entityManager->getRepository(KomojuOrder::class);
        $this->log_service = $logService;
        $this->order_state_machine = $orderStateMachine;
        $this->purchase_flow = $shoppingPurchaseFlow;
    }

    /**
     * Run purchase-flow commit() when a webhook is first to finalize the order
     * (customer never returned). Records buy stats + order date; stock/points
     * were already applied in prepare(). Guarded by the caller's
     * PENDING/PROCESSING check; must not abort webhook processing on failure.
     */
    private function commitPurchaseFlow($order){
        try {
            $this->purchase_flow->commit($order, new \Eccube\Service\PurchaseFlow\PurchaseContext());
        } catch (\Exception $e) {
            $this->log_service->writeLog("webhook", $order->getId(), "purchase flow commit skipped: " . $e->getMessage());
        }
    }

    /**
     * Atomically claim the right to finalize an order that is still
     * PENDING/PROCESSING, moving it to $newStatusId in a single UPDATE. Returns
     * true only for the caller that actually transitioned the row, so the
     * customer-return path and webhooks (or duplicate deliveries) cannot both
     * run the non-idempotent purchase-flow commit. Falls back to an in-memory
     * status check in non-DB (test/stub) contexts.
     */
    private function claimFinalization($order, $newStatusId){
        try {
            $affected = $this->entityManager->getConnection()->executeStatement(
                'UPDATE dtb_order SET order_status_id = ? WHERE id = ? AND order_status_id IN (?, ?)',
                [$newStatusId, $order->getId(), OrderStatus::PENDING, OrderStatus::PROCESSING]
            );
            return ((int) $affected) > 0;
        } catch (\Throwable $e) {
            $currentStatus = $order->getOrderStatus()->getId();
            return in_array($currentStatus, [OrderStatus::PENDING, OrderStatus::PROCESSING]);
        }
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

        // Serialize against the admin refund action (which also takes this lock)
        // so concurrent refunds cannot overwrite each other's refunded_amount.
        try {
            $this->entityManager->lock($komoju_order, \Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);
        } catch (\Exception $e) {
            // No active transaction (e.g. test/stub context): proceed without lock.
        }

        $Order = $komoju_order->getOrder();
        if(empty($Order)){
            $this->log_service->writeLog("webhook[refund]", 0, "no EC-CUBE order for payment: $komoju_payment_id");
            return;
        }

        // KOMOJU sends two events for one refund (payment.refunded +
        // payment.refund.created), both routed here. Record the refund and log
        // it exactly once across both deliveries.
        $refund_ids = [];
        $refund_amount = 0;
        foreach($refunds as $refund){
            $refund_ids[] = $refund->id;
            $refund_amount += $refund->amount;
        }
        sort($refund_ids); // canonical order so the WHERE comparison is stable
        $newRefundIdSet = implode(",", $refund_ids);
        $previousAmount = (float) $komoju_order->getRefundedAmount();

        // Keep the in-memory entity current for the cancel check below.
        $komoju_order->setRefundId($newRefundIdSet);
        $komoju_order->setRefundedAmount($refund_amount);
        $this->entityManager->persist($komoju_order);

        // Decide whether to log via an atomic compare-and-swap: the UPDATE only
        // matches when the stored refund_id differs from this payload's id set.
        // affected >= 1 means this delivery recorded the refund (log it);
        // affected == 0 means a duplicate delivery already did (skip). This is
        // correct on MySQL, PostgreSQL and SQLite without relying on
        // SELECT ... FOR UPDATE or isolation-level behaviour.
        $claimed = false;
        try {
            $conn = $this->entityManager->getConnection();
            $affected = $conn->executeStatement(
                'UPDATE plg_komoju_order SET refund_id = ?, refunded_amount = ? '
                . 'WHERE id = ? AND COALESCE(refund_id, ?) <> ?',
                [$newRefundIdSet, $refund_amount, $komoju_order->getId(), '', $newRefundIdSet]
            );
            $claimed = ((int) $affected) > 0;
        } catch (\Exception $e) {
            // Fallback for non-DB (test/stub) contexts; not concurrency-safe.
            $claimed = ($refund_amount > $previousAmount);
        }

        // Log only the delivery that recorded the refund, reporting the
        // newly-added amount (correct for partial refunds).
        if($claimed){
            $newRefundAmount = $refund_amount - $previousAmount;
            $this->log_service->writeLog("webhook[refund]", $Order->getId(), "refund confirmed (amount=$newRefundAmount)", true);
        }

        // Cancel the order once fully refunded, based on the actually-captured
        // amount (fall back to order total for rows without captured_amount).
        // Idempotent: can() returns false once already cancelled.
        $captured_basis = $komoju_order->getCapturedAmount() !== null
            ? (int)$komoju_order->getCapturedAmount()
            : (int)$Order->getPaymentTotal();
        if($refund_amount >= $captured_basis){
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

        // Atomically claim finalization so a concurrent SessionReturnController
        // (or duplicate webhook) cannot also run commitPurchaseFlow() and
        // double-count buy stats/points. Only the winner proceeds.
        if($this->claimFinalization($order, OrderStatus::NEW)){
            $this->commitPurchaseFlow($order);
            $order->setOrderStatus($this->entityManager->find(OrderStatus::class, OrderStatus::NEW));
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
        if(isset($object->data->amount)){
            $komoju_order->setCapturedAmount((int)$object->data->amount);
        }
        $this->entityManager->persist($komoju_order);
        $this->entityManager->flush();

        $order = $komoju_order->getOrder();
        if(empty($order)){
            $this->log_service->writeLog("webhook[captured]", 0, "no EC-CUBE order for payment: $komoju_payment_id");
            return ;
        }
        $this->log_service->writeLog("webhook[captured]", $order->getId(), "payment captured", true);
        // Atomically claim finalization so a concurrent SessionReturnController
        // (or duplicate webhook) cannot also run commitPurchaseFlow() and
        // double-count buy stats/points. Only the winner records buy stats;
        // the status is set to PAID regardless (captured is terminal).
        if($this->claimFinalization($order, OrderStatus::PAID)){
            $this->commitPurchaseFlow($order);
        }
        $order->setPaymentDate($captured_at);
        $OrderStatus = $this->entityManager->getRepository(OrderStatus::class)->find(OrderStatus::PAID);
        $order->setOrderStatus($OrderStatus);
        $this->entityManager->persist($order);
        // flush() with no argument: flush($entity) was always semantically
        // "compute changes for this entity only" and was deprecated in
        // Doctrine ORM 2.7 / removed in 3.0. The argument-less form is the
        // documented replacement and is the only form the EC-CUBE
        // 4.2/4.3 ORM (^2.11) supports without a deprecation warning.
        $this->entityManager->flush();
    }
    public function paymentCanceled($object){ $this->handleCancelEvent($object, 'canceled', 'payment cancelled'); }
    public function paymentExpired($object){ $this->handleCancelEvent($object, 'expired', 'payment expired'); }
    public function paymentFailed($object){ $this->handleCancelEvent($object, 'failed', 'payment failed'); }
    private function handleCancelEvent($object, $tag, $message){
        $komoju_payment_id = $object->data->id;
        // Use findKomojuOrder() (payment id + metadata + session fallbacks):
        // for failed/expired/cancelled the payment id may not be stored yet
        // (customer never returned), else the order stays stuck in PENDING.
        $komoju_order = $this->findKomojuOrder($object);
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
        // Multi-key lookup so an expired/cancelled change is not missed when
        // the payment id was never persisted on the KomojuOrder.
        $komoju_order = $this->findKomojuOrder($object);
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
        // Idempotency guard: only skip once the cancel actually completed.
        // canceled_at is set at the END of a successful cancel/rollback (not
        // here), so a duplicate/redelivered webhook can retry if an earlier
        // attempt could not act yet.
        if($komoju_order->getCanceledAt()){
            return;
        }

        $Order = $komoju_order->getOrder();
        if(empty($Order)){
            return;
        }

        $currentStatus = $Order->getOrderStatus()->getId();
        if ($currentStatus == OrderStatus::CANCEL) {
            $this->markCanceled($komoju_order);
            return;
        }

        if (in_array($currentStatus, [OrderStatus::PENDING, OrderStatus::PROCESSING])) {
            // The order never finalized (customer abandoned the hosted page).
            // The state machine forbids PENDING/PROCESSING -> CANCEL, so it
            // would leave stock/points reserved forever. Release them by
            // rolling back the purchase flow, then force the terminal CANCEL
            // status directly (there is no core job that reaps stale
            // PENDING/PROCESSING orders).
            $this->purchase_flow->rollback($Order, new \Eccube\Service\PurchaseFlow\PurchaseContext());
            $Order->setOrderStatus($this->entityManager->find(OrderStatus::class, OrderStatus::CANCEL));
            $this->entityManager->persist($Order);
            $this->entityManager->flush();
            $this->markCanceled($komoju_order);
            return;
        }

        // NEW / IN_PROGRESS / PAID: use the state machine, which fires the
        // workflow cancel event so EC-CUBE core's own listeners run
        // rollbackStock / rollbackUsePoint. A single flush persists those
        // mutations alongside the status change.
        $OrderStatus = $this->entityManager->find(OrderStatus::class, OrderStatus::CANCEL);
        if ($this->order_state_machine->can($Order, $OrderStatus)) {
            $this->order_state_machine->apply($Order, $OrderStatus);
            $this->entityManager->flush();

            // 会員の場合、購入回数、購入金額などを更新
            if ($Customer = $Order->getCustomer()) {
                $this->entityManager->getRepository(Order::class)->updateOrderSummary($Customer);
                $this->entityManager->flush();
            }
            $this->markCanceled($komoju_order);
        }
    }

    private function markCanceled($komoju_order){
        if($komoju_order->getCanceledAt()){
            return;
        }
        $komoju_order->setCanceledAt(new \DateTime());
        $this->entityManager->persist($komoju_order);
        $this->entityManager->flush();
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
