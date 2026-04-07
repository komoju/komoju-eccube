<?php

namespace Plugin\komoju\Service;

use Doctrine\ORM\EntityManagerInterface;
use Plugin\komoju\Entity\KomojuLog;


class LogService{
    protected $entityManager;

    public function __construct(EntityManagerInterface $entityManager){
        $this->entityManager = $entityManager;
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