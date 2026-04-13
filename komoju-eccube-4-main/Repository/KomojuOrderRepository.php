<?php

namespace Plugin\Komoju\Repository;

use Symfony\Bridge\Doctrine\RegistryInterface;
use Eccube\Repository\AbstractRepository;
use Plugin\Komoju\Entity\KomojuOrder;

class KomojuOrderRepository extends AbstractRepository{
    
    public function __construct(RegistryInterface $registry){
        parent::__construct($registry, KomojuOrder::class);
    }    
}