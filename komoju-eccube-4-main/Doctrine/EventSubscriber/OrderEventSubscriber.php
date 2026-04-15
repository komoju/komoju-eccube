<?php

namespace Plugin\Komoju\Doctrine\EventSubscriber;

use Doctrine\Common\EventSubscriber;
use Doctrine\Common\Persistence\Event\LifecycleEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\EntityManagerInterface;
use Eccube\Entity\Order;

use Eccube\Entity\Master\OrderStatus;
use Plugin\Komoju\Service\Method\KomojuPayment;
use Plugin\Komoju\Service\KomojuService;

class OrderEventSubscriber implements EventSubscriber{
    protected $komoju_service;
    protected $entityManager;

    public function __construct(EntityManagerInterface $entityManager, KomojuService $komojuService){
        $this->entityManager = $entityManager;
        $this->komoju_service = $komojuService;
    }

    public function getSubscribedEvents(){
        return [
            Events::postUpdate,
        ];
    }
    public function postUpdate(LifecycleEventArgs $args){
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