<?php

namespace Plugin\komoju42\Doctrine\EventSubscriber;

use Doctrine\Common\EventSubscriber;
// use Doctrine\Common\Persistence\Event\LifecycleEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\Event\PostUpdateEventArgs;

use Psr\Container\ContainerInterface;
use Eccube\Entity\Order;
use Eccube\Entity\Payment;
use Eccube\Entity\Master\OrderStatus;
use Plugin\komoju42\Service\Method\KomojuMultiPay;

class OrderEventSubscriber implements EventSubscriber{
    protected $container;
    protected $komoju_service;
    protected $entityManager;

    public function __construct(ContainerInterface $container){
        $this->container = $container;
        $this->komoju_service = $container->get('plg_komoju42.service.komoju_service');
        $this->entityManager = $container->get('doctrine.orm.entity_manager');
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