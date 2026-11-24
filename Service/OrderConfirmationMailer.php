<?php

namespace Plugin\Komoju42\Service;

use Doctrine\Persistence\ManagerRegistry;
use Eccube\Entity\Order;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\TerminateEvent;

class OrderConfirmationMailer
{
    private const REQUEST_KEY = '_komoju_order_confirmation';

    private $doctrine;
    private $getMailService;
    private $requests;

    public function __construct(ManagerRegistry $doctrine, \Closure $getMailService, RequestStack $requests)
    {
        $this->doctrine = $doctrine;
        $this->getMailService = $getMailService;
        $this->requests = $requests;
    }

    public function schedule(Order $order, string $token): void
    {
        $this->requests->getMainRequest()->attributes->set(self::REQUEST_KEY, [$order, $token]);
    }

    public function hasPending(TerminateEvent $event): bool
    {
        return $event->getRequest()->attributes->has(self::REQUEST_KEY);
    }

    public function onKernelTerminate(TerminateEvent $event): void
    {
        $attributes = $event->getRequest()->attributes;
        $confirmation = $attributes->get(self::REQUEST_KEY);
        if ($confirmation === null) {
            return;
        }
        $attributes->remove(self::REQUEST_KEY);
        [$order, $token] = $confirmation;
        $em = $this->doctrine->getManager();
        $connection = $em->getConnection();
        if ($connection->isTransactionActive() || !$connection->isAutoCommit()) {
            log_error('Order confirmation requires a completed request transaction', ['order_id' => $order->getId()]);
            return;
        }
        try {
            if (!$connection->fetchOne('SELECT 1 FROM plg_komoju_order WHERE order_id = ? AND confirmation_token = ?', [$order->getId(), $token])) {
                return;
            }

            $connection->beginTransaction();
            try {
                ($this->getMailService)()->sendOrderMail($order);
                $em->flush();
                if ($connection->getTransactionNestingLevel() !== 1) {
                    throw new \LogicException('Order mail left an unbalanced transaction');
                }
                $connection->commit();
            } finally {
                while ($connection->isTransactionActive()) {
                    $connection->rollBack();
                }
            }
        } catch (\Throwable $e) {
            $em->clear();
            if (!$em->isOpen()) {
                $this->doctrine->resetManager();
            }
            log_error('KOMOJU order confirmation email failed', ['order_id' => $order->getId(), 'exception' => $e]);
        }
    }
}
