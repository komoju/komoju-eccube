<?php

namespace Plugin\Komoju42\EventListener;

use Doctrine\ORM\EntityManagerInterface;
use Eccube\EventListener\TransactionListener;
use Plugin\Komoju42\Service\OrderConfirmationMailer;
use Symfony\Component\HttpKernel\Event\TerminateEvent;

class OrderConfirmationTransactionListener extends TransactionListener
{
    private $confirmationMailer;

    public function __construct(EntityManagerInterface $em, OrderConfirmationMailer $confirmationMailer, $isEnabled = true)
    {
        parent::__construct($em, $isEnabled);
        $this->confirmationMailer = $confirmationMailer;
    }

    public function onKernelTerminate(TerminateEvent $event)
    {
        $connection = $this->em->getConnection();
        $active = $connection->isTransactionActive();
        if (!$this->confirmationMailer->hasPending($event)
            || ($active && (!$this->isEnabled || $connection->getTransactionNestingLevel() !== 1 || $connection->isRollbackOnly()))) {
            parent::onKernelTerminate($event);
            return;
        }

        $autoCommit = $connection->isAutoCommit();
        try {
            // Commit the request without reopening a shared transaction before mail starts.
            if (!$autoCommit) {
                $connection->setAutoCommit(true);
            } elseif ($active) {
                $connection->commit();
            }
            $this->confirmationMailer->onKernelTerminate($event);
        } finally {
            while ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            $connection->setAutoCommit($autoCommit);
            if (!$autoCommit && $active) {
                $connection->beginTransaction();
            }
        }
    }
}
