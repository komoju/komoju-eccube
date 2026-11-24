<?php

namespace Plugin\Komoju42\Service;

use Doctrine\DBAL\Connections\PrimaryReadReplicaConnection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Eccube\Entity\Order;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\TerminateEvent;

class OrderConfirmationMailer
{
    private const REQUEST_KEY = '_komoju_order_confirmation';

    private $em;
    private $getMailService;
    private $requests;

    public function __construct(EntityManagerInterface $em, \Closure $getMailService, RequestStack $requests)
    {
        $this->em = $em;
        $this->getMailService = $getMailService;
        $this->requests = $requests;
    }

    public function schedule(Order $order, string $token): void
    {
        $this->requests->getMainRequest()->attributes->set(self::REQUEST_KEY, [$order, $token]);
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
        $connection = $this->em->getConnection();
        try {
            // EC-CUBE may have rolled back and reopened a transaction. Only this winner's committed token authorizes mail.
            $reader = DriverManager::getConnection($connection->getParams(), $connection->getConfiguration());
            try {
                if ($reader instanceof PrimaryReadReplicaConnection) {
                    $reader->ensureConnectedToPrimary();
                }
                $committed = $reader->fetchOne('SELECT 1 FROM plg_komoju_order WHERE order_id = ? AND confirmation_token = ?', [$order->getId(), $token]);
            } finally {
                $reader->close();
            }
            if (!$committed) {
                return;
            }

            ($this->getMailService)()->sendOrderMail($order);
            $this->em->flush();
            if ($connection->isTransactionActive()) {
                $connection->commit();
            }
        } catch (\Throwable $e) {
            log_error('KOMOJU order confirmation email failed', ['order_id' => $order->getId(), 'exception' => $e]);
        }
    }
}
