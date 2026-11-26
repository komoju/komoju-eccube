<?php

namespace Plugin\Komoju42\Service;

use Doctrine\ORM\EntityManagerInterface;
use Eccube\Entity\Master\OrderStatus;
use Eccube\Service\PurchaseFlow\Processor\PointProcessor;
use Eccube\Service\PurchaseFlow\Processor\StockReduceProcessor;
use Eccube\Service\PurchaseFlow\PurchaseContext;
use Eccube\Service\PurchaseFlow\PurchaseFlow;
use Plugin\Komoju42\Entity\KomojuOrder;

/**
 * Releases the stock and points that KomojuPayment::apply() reserves for a
 * 決済処理中 order, once KOMOJU shows that nothing on the session can still be paid.
 * Shared by the customer return/status pages and the admin order page.
 */
class PendingReservationService
{
    use PaymentAttemptTransitionTrait;

    /** Payment states that can never move money again. */
    const DEAD_PAYMENT_STATUSES = ['failed', 'cancelled', 'expired'];

    protected $entityManager;
    protected $purchase_flow;
    protected $point_processor;
    protected $stock_reduce_processor;
    protected $log_service;

    public function __construct(
        EntityManagerInterface $entityManager,
        PurchaseFlow $shoppingPurchaseFlow,
        PointProcessor $pointProcessor,
        StockReduceProcessor $stockReduceProcessor,
        LogService $logService
    ){
        $this->entityManager = $entityManager;
        $this->purchase_flow = $shoppingPurchaseFlow;
        $this->point_processor = $pointProcessor;
        $this->stock_reduce_processor = $stockReduceProcessor;
        $this->log_service = $logService;
    }

    /**
     * True when nothing on this session can still take money: the session is
     * closed (cancelled, completed, or expired) and its payment is absent or dead.
     * KOMOJU only ever replaces a session payment, so a null payment means none exists.
     */
    public function isReleasable(array $session): bool
    {
        $status = $session['status'] ?? null;
        $closed = in_array($status, ['cancelled', 'completed'], true)
            || ($status === 'pending' && ($session['expired'] ?? false) === true);
        if (!$closed || !array_key_exists('payment', $session)) {
            return false;
        }
        $payment = $session['payment'];
        return $payment === null
            || (is_array($payment) && in_array($payment['status'] ?? null, self::DEAD_PAYMENT_STATUSES, true));
    }

    /**
     * Ask KOMOJU to close the session, which also queues cancellation of any
     * payment on it. Returns the closed session, or null if not confirmed.
     */
    public function closeSession($komojuClient, string $sessionId, int $orderId): ?array
    {
        try {
            $closed = $komojuClient->cancelSession($sessionId);
        } catch (\Throwable $e) {
            log_error($e);
            $closed = null;
        }
        if ($komojuClient->getStatusCode() != 200 || !is_array($closed) || ($closed['status'] ?? null) !== 'cancelled') {
            // Usually the session completed or closed meanwhile; the status check decides.
            $this->log_service->writeLog("cancelSession", $orderId, "session cancel not confirmed: code=" . $komojuClient->getStatusCode());
            return null;
        }
        return $closed;
    }

    /**
     * Customer-side release: undo the whole purchase flow apply() prepared and
     * reopen the order for checkout. Must run in the customer's session, because
     * the shopping flow validates the order against the current cart.
     */
    public function reopenForCheckout(KomojuOrder $attempt, $Order): bool
    {
        return $this->release($attempt, $Order, OrderStatus::PROCESSING, function () use ($Order) {
            $this->purchase_flow->rollback($Order, new PurchaseContext());
        });
    }

    /**
     * Shop-side release (no customer cart): restore stock and points and cancel
     * the order, as WebhookService::cancelOrder() does for terminal payments.
     */
    public function cancel(KomojuOrder $attempt, $Order): bool
    {
        return $this->release($attempt, $Order, OrderStatus::CANCEL, function () use ($Order) {
            $context = new PurchaseContext();
            $this->point_processor->rollback($Order, $context);
            $this->stock_reduce_processor->rollback($Order, $context);
        });
    }

    /**
     * Restore the reservation of a 決済処理中 order exactly once. Marking the
     * attempt cancelled makes late webhooks for it inert. Callers must first
     * establish isReleasable() for this attempt's session.
     */
    private function release(KomojuOrder $attempt, $Order, int $targetStatusId, callable $restore): bool
    {
        return $this->transactional(function () use ($attempt, $Order, $targetStatusId, $restore) {
            $this->entityManager->lock($Order, \Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);
            $this->entityManager->lock($attempt, \Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);
            $this->entityManager->refresh($Order);
            $this->entityManager->refresh($attempt);
            if ($Order->getOrderStatus()->getId() != OrderStatus::PENDING
                || !$this->isCurrentAttempt($attempt, $Order)
                || !$this->claimCancellation($attempt)) {
                return false;
            }
            $restore();
            $Order->setOrderStatus($this->entityManager->find(OrderStatus::class, $targetStatusId));
            $this->entityManager->persist($attempt);
            $this->entityManager->flush();
            return true;
        });
    }

    public function isCurrentAttempt(KomojuOrder $attempt, $Order): bool
    {
        $current = $this->entityManager->getRepository(KomojuOrder::class)
            ->findOneBy(['Order' => $Order], ['id' => 'DESC']);
        if ($current === $attempt) {
            return true;
        }
        return $current && $current->getId() && $current->getId() === $attempt->getId();
    }
}
