<?php

namespace Plugin\komoju\Repository;

use Eccube\Repository\AbstractRepository;
use Symfony\Bridge\Doctrine\RegistryInterface;
use Plugin\komoju\Entity\KomojuPay;

class KomojuPayRepository extends AbstractRepository{
    /**
     * KomojuPayRepository constructor.
     *
     * @param RegistryInterface $registry
     */
    public function __construct(RegistryInterface $registry)
    {
        parent::__construct($registry, KomojuPay::class);
    }
    public function getEnabledMethods(){
        return $this->findBy(['enabled' =>  true]);
    }
    public function getEnabledMethodsString(){
        $methods = $this->getEnabledMethods();
        
        $arr = [];
        foreach($methods as $method){
            $arr[] = $method->getName();
        }
        return $arr;
    }
}