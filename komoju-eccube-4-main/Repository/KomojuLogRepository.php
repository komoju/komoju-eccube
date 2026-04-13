<?php

namespace Plugin\Komoju\Repository;

use Symfony\Bridge\Doctrine\RegistryInterface;
use Eccube\Repository\AbstractRepository;
use Plugin\Komoju\Entity\KomojuLog;

class KomojuLogRepository extends AbstractRepository{
    
    public function __construct(RegistryInterface $registry){
        parent::__construct($registry, KomojuLog::class);
    }    
}