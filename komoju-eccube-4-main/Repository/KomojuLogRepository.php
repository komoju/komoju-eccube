<?php

namespace Plugin\komoju\Repository;

use Symfony\Bridge\Doctrine\RegistryInterface;
use Eccube\Repository\AbstractRepository;
use Plugin\komoju\Entity\KomojuLog;

class KomojuLogRepository extends AbstractRepository{
    
    public function __construct(RegistryInterface $registry){
        parent::__construct($registry, KomojuLog::class);
    }    
}