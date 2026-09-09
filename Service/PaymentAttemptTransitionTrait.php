<?php

namespace Plugin\Komoju42\Service;

use Eccube\Entity\Master\OrderStatus;

trait PaymentAttemptTransitionTrait
{
    private function transactional(callable $callback)
    {
        $connection = $this->entityManager->getConnection();
        if(!$connection->isTransactionActive()){
            $connection->beginTransaction();
            try {
                $result = $callback();
                $connection->commit();
                return $result;
            } catch (\Throwable $e) {
                if($connection->isTransactionActive()){
                    $connection->rollBack();
                }
                $this->entityManager->clear();
                throw $e;
            }
        }

        static $savepointCounter = 0;
        $savepoint = 'KOMOJU_' . ++$savepointCounter;
        $connection->createSavepoint($savepoint);
        try {
            $result = $callback();
            $connection->releaseSavepoint($savepoint);
            return $result;
        } catch (\Throwable $e) {
            $connection->rollbackSavepoint($savepoint);
            $connection->releaseSavepoint($savepoint);
            $this->entityManager->clear();
            throw $e;
        }
    }

    private function claimFinalization($order, $newStatusId)
    {
        $affected = $this->entityManager->getConnection()->executeStatement(
            'UPDATE dtb_order SET order_status_id = ? WHERE id = ? AND order_status_id IN (?, ?)',
            [$newStatusId, $order->getId(), OrderStatus::PENDING, OrderStatus::PROCESSING]
        );
        return (int) $affected > 0;
    }

    private function bindPaymentId($attempt, $paymentId)
    {
        $stored = $attempt->getKomojuPaymentId();
        if (!empty($stored)) {
            return hash_equals((string) $stored, (string) $paymentId);
        }
        if ($attempt->getId()) {
            $affected = $this->entityManager->getConnection()->executeStatement(
                'UPDATE plg_komoju_order SET komoju_payment_id = ? WHERE id = ? AND komoju_payment_id IS NULL',
                [$paymentId, $attempt->getId()]
            );
            if ((int) $affected < 1) {
                return false;
            }
        }
        $attempt->setKomojuPaymentId($paymentId);
        return true;
    }

    private function claimCaptured($attempt, $capturedAt, $amount)
    {
        if ($attempt->isCaptured()) {
            return true;
        }
        if ($attempt->getCanceledAt()) {
            return false;
        }
        if ($attempt->getId()) {
            $affected = $this->entityManager->getConnection()->executeStatement(
                'UPDATE plg_komoju_order SET captured_at = ?, captured_amount = ? '
                . 'WHERE id = ? AND captured_at IS NULL AND canceled_at IS NULL',
                [$capturedAt->format('Y-m-d H:i:s'), $amount, $attempt->getId()]
            );
            if ((int) $affected < 1) {
                return false;
            }
        }
        $attempt->setCapturedAt($capturedAt);
        if ($amount !== null) {
            $attempt->setCapturedAmount($amount);
        }
        return true;
    }

    private function claimCancellation($attempt)
    {
        if ($attempt->getCanceledAt() || $attempt->isCaptured()) {
            return false;
        }
        $canceledAt = new \DateTime();
        if ($attempt->getId()) {
            $affected = $this->entityManager->getConnection()->executeStatement(
                'UPDATE plg_komoju_order SET canceled_at = ? '
                . 'WHERE id = ? AND canceled_at IS NULL AND captured_at IS NULL',
                [$canceledAt->format('Y-m-d H:i:s'), $attempt->getId()]
            );
            if ((int) $affected < 1) {
                return false;
            }
        }
        $attempt->setCanceledAt($canceledAt);
        return true;
    }
}
