<?php

namespace Plugin\Komoju42\Doctrine\EventSubscriber;

use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\EntityManagerInterface;
use Eccube\Entity\Order;
use Eccube\Entity\Master\OrderStatus;
use Plugin\Komoju42\Service\Method\KomojuPayment;
use Plugin\Komoju42\Service\KomojuService;

// Registered as doctrine.event_listener (not doctrine.event_subscriber) in
// services.yaml so Symfony doctrine-bridge 6.3+ does not emit a deprecation.
class OrderEventSubscriber
{
    protected $komoju_service;
    protected $entityManager;

    public function __construct(EntityManagerInterface $entityManager, KomojuService $komojuService){
        $this->entityManager = $entityManager;
        $this->komoju_service = $komojuService;
    }

    public function postUpdate(PostUpdateEventArgs $args){
        $Order = $args->getObject();
        if(!$Order instanceof Order){
            return;
        }
        $payment = $Order->getPayment();
        if(!$payment || $payment->getMethodClass() !== KomojuPayment::class){
            return;
        }
        if($Order->getOrderStatus()->getId() == OrderStatus::CANCEL){
            $this->komoju_service->cancelKomojuOrderByOrder($Order);
        }
    }
}