<?php

namespace Plugin\komoju42\Repository;

// use Symfony\Bridge\Doctrine\RegistryInterface;
use Doctrine\Persistence\ManagerRegistry as RegistryInterface;
use Eccube\Repository\AbstractRepository;
use Plugin\komoju42\Entity\KomojuOrder;

class KomojuOrderRepository extends AbstractRepository{
    
    public function __construct(RegistryInterface $registry){
        parent::__construct($registry, KomojuOrder::class);
    }    
}