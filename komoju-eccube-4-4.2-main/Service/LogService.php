<?php

namespace Plugin\Komoju42\Service;

use Doctrine\ORM\EntityManagerInterface;
use Plugin\Komoju42\Entity\KomojuConfig;
use Plugin\Komoju42\Entity\KomojuLog;


class LogService{
    protected $entityManager;
    protected $cachedConfig;
    protected $configLoaded = false;

    public function __construct(EntityManagerInterface $entityManager){
        $this->entityManager = $entityManager;
    }

    /**
     * Logging must never break the caller: writeLog() is called from error
     * handlers whose whole purpose is to recover from a failure that may have
     * closed the EntityManager, in which case flush() itself throws.
     */
    public function writeLog($api, $order_id, $msg, $protected = false){
        try {
            if (!$protected) {
                if (!$this->configLoaded) {
                    $this->cachedConfig = $this->entityManager->getRepository(KomojuConfig::class)->findOneBy([]);
                    $this->configLoaded = true;
                }
                if ($this->cachedConfig && !$this->cachedConfig->isLoggingEnabled()) {
                    return;
                }
            }

            $log = new KomojuLog;
            $log->setApi($api);
            $log->setOrderId($order_id);
            $log->setMsg($msg);
            $log->setCreatedAt(new \DateTime());
            if ($protected) {
                $log->setIsProtected(true);
            }

            $this->entityManager->persist($log);
            $this->entityManager->flush();
        } catch (\Throwable $e) {
            log_error('KOMOJU writeLog failed: ' . $e->getMessage());
        }
    }
}
