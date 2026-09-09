<?php

namespace Plugin\Komoju42\Service;

use Doctrine\ORM\EntityManagerInterface;
use Plugin\Komoju42\Entity\KomojuOrder;
use Eccube\Entity\Master\OrderStatus;
use Eccube\Service\OrderStateMachine;
use Eccube\Service\PurchaseFlow\Processor\PointProcessor;
use Eccube\Service\PurchaseFlow\Processor\StockReduceProcessor;
use Eccube\Service\PurchaseFlow\PurchaseContext;
use Eccube\Entity\Order;
class WebhookService{
    use PaymentAttemptTransitionTrait;

    protected $entityManager;
    protected $log_service;
    protected $komoju_order_repo;
    protected $order_state_machine;
    protected $purchase_flow;
    protected $point_processor;
    protected $stock_reduce_processor;


    public function __construct(
        EntityManagerInterface $entityManager,
        OrderStateMachine $orderStateMachine,
        LogService $logService,
        \Eccube\Service\PurchaseFlow\PurchaseFlow $shoppingPurchaseFlow,
        PointProcessor $pointProcessor,
        StockReduceProcessor $stockReduceProcessor
    ){
        $this->entityManager = $entityManager;
        $this->komoju_order_repo = $this->entityManager->getRepository(KomojuOrder::class);
        $this->log_service = $logService;
        $this->order_state_machine = $orderStateMachine;
        $this->purchase_flow = $shoppingPurchaseFlow;
        $this->point_processor = $pointProcessor;
        $this->stock_reduce_processor = $stockReduceProcessor;
    }

    private function commitPurchaseFlow($order){
        $this->purchase_flow->commit(
            $order,
            new \Eccube\Service\PurchaseFlow\PurchaseContext()
        );
    }
    private function rejectAttempt($tag, $orderId, $reason){
        $this->log_service->writeLog("webhook[$tag]", $orderId, "rejected: $reason mismatch");
        return false;
    }

    private function validateAttempt($komoju_order, $object, $tag){
        $data = $object->data;
        $order = $komoju_order->getOrder();
        $orderId = $order ? $order->getId() : 0;
        $storedSession = $komoju_order->getKomojuSessionId();
        $storedPayment = $komoju_order->getKomojuPaymentId();
        $eventPayment = isset($data->id) ? (string)$data->id : '';

        if(!empty($storedPayment) && !hash_equals((string)$storedPayment, $eventPayment)){
            return $this->rejectAttempt($tag, $orderId, 'payment');
        }

        if(isset($data->session) && $storedSession !== (string)$data->session){
            return $this->rejectAttempt($tag, $orderId, 'session');
        }
        if($komoju_order->getExpectedAmount() !== null
            && (!isset($data->amount) || (int)$data->amount !== (int)$komoju_order->getExpectedAmount())){
            return $this->rejectAttempt($tag, $orderId, 'amount');
        }
        if($komoju_order->getExpectedCurrency() !== null
            && (!isset($data->currency) || strtoupper((string)$data->currency) !== strtoupper((string)$komoju_order->getExpectedCurrency()))){
            return $this->rejectAttempt($tag, $orderId, 'currency');
        }
        if(isset($data->metadata->eccube_order_id)
            && $order
            && (string)$data->metadata->eccube_order_id !== (string)$order->getId()){
            return $this->rejectAttempt($tag, $orderId, 'order');
        }
        return true;
    }

    private function isCurrentAttempt($komoju_order){
        $order = $komoju_order->getOrder();
        if(!$order){
            return false;
        }
        $current = $this->komoju_order_repo->findOneBy(['Order' => $order], ['id' => 'DESC']);
        if($current === $komoju_order){
            return true;
        }
        if($current && $current->getId() && $current->getId() === $komoju_order->getId()){
            return true;
        }
        return $current
            && $komoju_order->getKomojuSessionId()
            && $current->getKomojuSessionId() === $komoju_order->getKomojuSessionId();
    }

    public function paymentRefunded($object){
        $paymentId = $object->data->id;
        $refunds = $object->data->refunds;
        if(empty($refunds)){
            $this->log_service->writeLog("webhook[refund]", 0, "no refunds in payload for payment: $paymentId");
            return;
        }

        $attempt = $this->komoju_order_repo->findOneBy(['komoju_payment_id' => $paymentId]);
        if(!$attempt || !$attempt->getOrder()){
            $this->log_service->writeLog("webhook[refund]", 0, "no order found for payment: $paymentId");
            return;
        }
        $order = $attempt->getOrder();
        $refundIds = [];
        $refundAmount = 0;
        foreach($refunds as $refund){
            $refundIds[] = $refund->id;
            $refundAmount += $refund->amount;
        }
        $refundIdSet = KomojuOrder::canonicalRefundIds($refundIds);

        $newAmount = $this->transactional(function () use ($attempt, $order, $refundIdSet, $refundAmount) {
            $this->entityManager->lock($order, \Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);
            $this->entityManager->lock($attempt, \Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);
            $this->entityManager->refresh($order);
            $this->entityManager->refresh($attempt);
            $previousAmount = (float)$attempt->getRefundedAmount();
            if($refundAmount <= $previousAmount){
                return 0;
            }

            $affected = $this->entityManager->getConnection()->executeStatement(
                'UPDATE plg_komoju_order SET refund_id = ?, refunded_amount = ? '
                . 'WHERE id = ? AND COALESCE(refunded_amount, 0) < ?',
                [$refundIdSet, $refundAmount, $attempt->getId(), $refundAmount]
            );
            if((int)$affected < 1){
                return 0;
            }

            $attempt->setRefundId($refundIdSet);
            $attempt->setRefundedAmount($refundAmount);
            $this->entityManager->persist($attempt);
            $capturedAmount = $attempt->getCapturedAmount() !== null
                ? (int)$attempt->getCapturedAmount()
                : (int)$order->getPaymentTotal();
            if($refundAmount >= $capturedAmount){
                $cancelStatus = $this->entityManager->getRepository(OrderStatus::class)->find(OrderStatus::CANCEL);
                if($this->order_state_machine->can($order, $cancelStatus)){
                    $this->order_state_machine->apply($order, $cancelStatus);
                }
            }
            $this->entityManager->flush();
            return $refundAmount - $previousAmount;
        });

        if($newAmount > 0){
            $this->log_service->writeLog("webhook[refund]", $order->getId(), "refund confirmed (amount=$newAmount)", true);
        }
    }
    public function paymentAuthorized($object){
        $paymentId = $object->data->id;
        $attempt = $this->findKomojuOrder($object);
        if(!$attempt){
            $this->log_service->writeLog("webhook[authorized]", 0, "no order found for payment: $paymentId");
            return;
        }
        $order = $attempt->getOrder();
        if(!$order || !$this->validateAttempt($attempt, $object, 'authorized')){
            return;
        }

        $processed = $this->transactional(function () use ($attempt, $object, $order, $paymentId) {
            $this->entityManager->lock($order, \Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);
            $this->entityManager->lock($attempt, \Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);
            $this->entityManager->refresh($order);
            $this->entityManager->refresh($attempt);
            if($attempt->getCanceledAt() || $order->getOrderStatus()->getId() == OrderStatus::CANCEL
                || !$this->bindPaymentId($attempt, $paymentId)){
                return false;
            }
            if(isset($object->data->payment_details->type)){
                $attempt->setType($object->data->payment_details->type);
            }
            $this->entityManager->persist($attempt);
            if($this->claimFinalization($order, OrderStatus::NEW)){
                $this->commitPurchaseFlow($order);
            }
            $this->entityManager->flush();
            return true;
        });

        $this->entityManager->refresh($order);
        if(!$processed){
            $this->log_service->writeLog("webhook[authorized]", 0, "payment correlation failed: $paymentId");
            return;
        }
        $this->log_service->writeLog("webhook[authorized]", $order->getId(), "payment authorized", true);
    }

    public function paymentCaptured($object){
        $paymentId = $object->data->id;
        $attempt = $this->findKomojuOrder($object);
        if(!$attempt){
            $this->log_service->writeLog("webhook[captured]", 0, "no order found for payment: $paymentId");
            return;
        }
        $order = $attempt->getOrder();
        if($attempt->isCaptured() && !$order){
            return;
        }
        if(!$this->validateAttempt($attempt, $object, 'captured')){
            return;
        }

        $capturedAt = isset($object->data->captured_at)
            ? new \DateTime($object->data->captured_at)
            : ($attempt->getCapturedAt() ?: new \DateTime());
        $capturedAmount = isset($object->data->amount) ? (int)$object->data->amount : null;
        $processed = $this->transactional(function () use ($attempt, $object, $order, $paymentId, $capturedAt, $capturedAmount) {
            if($order){
                $this->entityManager->lock($order, \Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);
            }
            $this->entityManager->lock($attempt, \Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);
            if($order){
                $this->entityManager->refresh($order);
            }
            $this->entityManager->refresh($attempt);
            if($order){
                $status = $order->getOrderStatus()->getId();
                if($attempt->isCaptured()
                    && !in_array($status, [OrderStatus::PENDING, OrderStatus::PROCESSING, OrderStatus::NEW])){
                    return true;
                }
                if($status == OrderStatus::CANCEL){
                    return false;
                }
            }
            if(!$this->bindPaymentId($attempt, $paymentId)
                || !$this->claimCaptured($attempt, $capturedAt, $capturedAmount)){
                return false;
            }
            if(isset($object->data->payment_details->type)){
                $attempt->setType($object->data->payment_details->type);
            }
            $this->entityManager->persist($attempt);
            if($order){
                $status = $order->getOrderStatus()->getId();
                if(in_array($status, [OrderStatus::PENDING, OrderStatus::PROCESSING])
                    && $this->claimFinalization($order, OrderStatus::PAID)){
                    $this->commitPurchaseFlow($order);
                }
                if(in_array($status, [OrderStatus::PENDING, OrderStatus::PROCESSING, OrderStatus::NEW])){
                    $order->setPaymentDate($capturedAt);
                    $order->setOrderStatus($this->entityManager->getRepository(OrderStatus::class)->find(OrderStatus::PAID));
                    $this->entityManager->persist($order);
                }
            }
            $this->entityManager->flush();
            return true;
        });

        if(!$processed){
            $this->log_service->writeLog("webhook[captured]", 0, "ignored: payment attempt is terminal");
            return;
        }
        if(!$order){
            $this->log_service->writeLog("webhook[captured]", 0, "no EC-CUBE order for payment: $paymentId");
            return;
        }
        $this->log_service->writeLog("webhook[captured]", $order->getId(), "payment captured", true);
    }

    public function paymentCanceled($object){ $this->handleCancelEvent($object, 'canceled', 'payment cancelled'); }
    public function paymentExpired($object){ $this->handleCancelEvent($object, 'expired', 'payment expired'); }
    public function paymentFailed($object){
        $paymentId = $object->data->id;
        $attempt = $this->findKomojuOrder($object);
        if(!$attempt){
            $this->log_service->writeLog("webhook[failed]", 0, "no order found for payment: $paymentId");
            return;
        }
        if(!$this->validateAttempt($attempt, $object, 'failed')){
            return;
        }
        $order = $attempt->getOrder();
        if($order){
            $this->log_service->writeLog("webhook[failed]", $order->getId(), "payment attempt failed; retry allowed", true);
        }
    }

    private function handleCancelEvent($object, $tag, $message){
        $paymentId = $object->data->id;
        $attempt = $this->findKomojuOrder($object);
        if(!$attempt){
            $this->log_service->writeLog("webhook[$tag]", 0, "no order found for payment: $paymentId");
            return;
        }
        if(!$this->validateAttempt($attempt, $object, $tag)){
            return;
        }
        $order = $attempt->getOrder();
        if(!$order){
            $this->log_service->writeLog("webhook[$tag]", 0, "no EC-CUBE order for payment: $paymentId");
            return;
        }
        $this->log_service->writeLog("webhook[$tag]", $order->getId(), $message, true);
        $this->cancelOrder($attempt, $paymentId);
    }
    public function paymentUpdated($object){
        // Multi-key lookup so an expired/cancelled change is not missed when
        // the payment id was never persisted on the KomojuOrder.
        $komoju_order = $this->findKomojuOrder($object);
        if(empty($komoju_order)){
            return;
        }
        if(!$this->validateAttempt($komoju_order, $object, 'updated')){
            return;
        }
        $status = $object->data->status;
        if(in_array($status, ["expired", "cancelled"])){
            $order = $komoju_order->getOrder();
            if($order){
                $this->log_service->writeLog("webhook[updated]", $order->getId(), "payment status changed to $status", true);
            }
            $this->cancelOrder($komoju_order, (string)$object->data->id);
        }
    }
    private function cancelOrder($attempt, $paymentId){
        if($attempt->getCanceledAt()){
            return;
        }
        $order = $attempt->getOrder();
        if(!$order){
            return;
        }

        $result = $this->transactional(function () use ($attempt, $order, $paymentId) {
            $this->entityManager->lock($order, \Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);
            $this->entityManager->lock($attempt, \Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);
            $this->entityManager->refresh($order);
            $this->entityManager->refresh($attempt);
            if(!$this->isCurrentAttempt($attempt)){
                return 'stale';
            }
            $status = $order->getOrderStatus()->getId();
            if($status == OrderStatus::PAID || $attempt->isCaptured()){
                return 'paid';
            }
            $storedPayment = $attempt->getKomojuPaymentId();
            if(!empty($storedPayment) && !hash_equals((string)$storedPayment, (string)$paymentId)){
                return 'payment_mismatch';
            }
            if(!$this->claimCancellation($attempt)){
                return 'terminal';
            }
            if(!$this->bindPaymentId($attempt, $paymentId)){
                throw new \RuntimeException('Failed to bind terminal payment ID.');
            }
            $this->entityManager->persist($attempt);
            if($status == OrderStatus::CANCEL){
                $this->entityManager->flush();
                return 'cancelled';
            }
            if(in_array($status, [OrderStatus::PENDING, OrderStatus::PROCESSING])){
                $context = new PurchaseContext();
                $this->point_processor->rollback($order, $context);
                $this->stock_reduce_processor->rollback($order, $context);
                $order->setOrderStatus($this->entityManager->find(OrderStatus::class, OrderStatus::CANCEL));
                $this->entityManager->persist($order);
                $this->entityManager->flush();
                return 'cancelled';
            }

            $cancelStatus = $this->entityManager->find(OrderStatus::class, OrderStatus::CANCEL);
            if($this->order_state_machine->can($order, $cancelStatus)){
                $this->order_state_machine->apply($order, $cancelStatus);
            }
            $this->entityManager->flush();
            if($Customer = $order->getCustomer()){
                $this->entityManager->getRepository(Order::class)->updateOrderSummary($Customer);
                $this->entityManager->flush();
            }
            return 'cancelled';
        });

        if($result === 'paid'){
            $this->log_service->writeLog("webhook[cancel]", $order->getId(), "ignored: order already paid");
        }elseif($result === 'payment_mismatch'){
            $this->rejectAttempt('cancel', $order->getId(), 'payment');
        }
    }


    /** Find an attempt by payment ID or exact session ID. */
    private function findKomojuOrder($object){
        $komoju_payment_id = $object->data->id;
        $komoju_order = $this->komoju_order_repo->findOneBy(['komoju_payment_id' => $komoju_payment_id]);
        if($komoju_order){
            return $komoju_order;
        }

        if(isset($object->data->session) && $object->data->session !== ''){
            return $this->komoju_order_repo->findOneBy([
                'komoju_session_id' => (string)$object->data->session,
            ]);
        }

        return null;
    }
}
