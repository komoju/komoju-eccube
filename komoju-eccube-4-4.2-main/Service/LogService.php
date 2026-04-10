<?php

namespace Plugin\Komoju42\Service;

use Doctrine\ORM\EntityManagerInterface;
use Plugin\Komoju42\Entity\KomojuConfig;
use Plugin\Komoju42\Entity\KomojuLog;


class LogService{
    protected $entityManager;

    public function __construct(EntityManagerInterface $entityManager){
        $this->entityManager = $entityManager;
    }

    public function writeLog($api, $order_id, $msg){
        $config = $this->entityManager->getRepository(KomojuConfig::class)->findOneBy([]);
        if ($config && !$config->isLoggingEnabled()) {
            return;
        }

        $log = new KomojuLog;
        $log->setApi($api);
        $log->setOrderId($order_id);
        $log->setMsg($msg);
        $log->setCreatedAt(new \DateTime());

        $this->entityManager->persist($log);
        $this->entityManager->flush();
    }
}
