<?php

namespace Plugin\komoju\Repository;

use Symfony\Bridge\Doctrine\RegistryInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Eccube\Repository\AbstractRepository;
use Eccube\Event\EventArgs;
use Plugin\komoju\Entity\KomojuConfig;
use Plugin\komoju\KomojuEvent;

class KomojuConfigRepository extends AbstractRepository{
    
    /**
     * @var EventDispatcher
     */
    protected $eventDispatcher;

    public function __construct(RegistryInterface $registry, EventDispatcherInterface $eventDispatcher){
        $this->eventDispatcher = $eventDispatcher;
        parent::__construct($registry, KomojuConfig::class);        
    }
    public function get(){
        $conf = $this->findBy([]);
        if(count($conf) == 0){
            return null;
        }
        return $conf[0];
    }
    public function getConfigByOrder($Order = null){
        $config_data = $this->get();

        $komoju_config = [
            'publishable_key'   =>  $config_data->getPublishableKey(),
            'secret_key'        =>  $config_data->getSecretKey(),
            'merchant_uuid'     =>  $config_data->getMerchantUuid(),
            'webhook_secret'    =>  $config_data->getWebhookSecret(),
            'capture_on'        =>  $config_data->isCaptureOn()
        ];

        $event = new EventArgs([
            'Order' =>  $Order,
            'KomojuConfig'  =>  $komoju_config
        ]);
        $this->eventDispatcher->dispatch(KomojuEvent::EVENT_KOMOJU_CONFIG_LOAD, $event);
        return $komoju_config;
    }
}