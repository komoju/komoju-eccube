<?php

namespace Plugin\komoju42\Service;

use Psr\Container\ContainerInterface;
use Eccube\Repository\PaymentRepository;
use Eccube\Entity\Payment;
use Eccube\Entity\PaymentOption;
use Eccube\Common\EccubeConfig;
use Plugin\komoju42\Entity\KomojuPay;
use Plugin\komoju42\Entity\KomojuConfig;
use Plugin\komoju42\Entity\KomojuLog;
use Plugin\komoju42\Service\Method\KomojuMultiPay;


class LogService{
    protected $container;    
    protected $entityManager;

    public function __construct(ContainerInterface $container){
        $this->container = $container;
        $this->entityManager = $container->get('doctrine.orm.entity_manager');
    }

    public function writeLog($api, $order_id, $msg){
        $log = new KomojuLog;
        $log->setApi($api);
        $log->setOrderId($order_id);
        $log->setMsg($msg);
        $log->setCreatedAt(new \DateTime());

        $this->entityManager->persist($log);
        $this->entityManager->flush();
    }
}