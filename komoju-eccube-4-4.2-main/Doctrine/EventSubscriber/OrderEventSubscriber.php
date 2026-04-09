<?php

namespace Plugin\Komoju42\Doctrine\EventSubscriber;

use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\Events;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\EntityManagerInterface;
use Eccube\Entity\Order;
use Eccube\Entity\Payment;
use Eccube\Entity\Master\OrderStatus;
use Plugin\Komoju42\Service\Method\KomojuMultiPay;
use Plugin\Komoju42\Service\KomojuService;

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
    public function postUpdate(PostUpdateEventArgs $args){
        $Order = $args->getObject();
        if($Order instanceof Order){
            if($Order->getPayment()->getId() !=
                $this->entityManager->getRepository(Payment::class)->findOneBy(['method_class' => KomojuMultiPay::class])->getId())
                return;
            if($Order->getOrderStatus()->getId() == OrderStatus::CANCEL){
                $this->komoju_service->cancelKomojuOrderByOrder($Order);
            }
        }
    }
}