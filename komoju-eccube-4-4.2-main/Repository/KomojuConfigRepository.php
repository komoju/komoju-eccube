<?php

namespace Plugin\Komoju42\Repository;

// use Symfony\Bridge\Doctrine\RegistryInterface;
use Doctrine\Persistence\ManagerRegistry as RegistryInterface;

use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Eccube\Repository\AbstractRepository;
use Eccube\Event\EventArgs;
use Plugin\Komoju42\Entity\KomojuConfig;
use Plugin\Komoju42\KomojuEvent;

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
        if($config_data === null){
            throw new \RuntimeException('KOMOJU plugin configuration not found. Please configure the plugin in the admin panel.');
        }

        $komoju_config = [
            'publishable_key'   =>  $config_data->getPublishableKey(),
            'secret_key'        =>  $config_data->getSecretKey(),
            'merchant_uuid'     =>  $config_data->getMerchantUuid(),
            'webhook_secret'    =>  $config_data->getWebhookSecret(),
            'capture_on'        =>  $config_data->isCaptureOn(),
            'log_retention_days' => $config_data->getLogRetentionDays(),
            'logging_enabled' => $config_data->isLoggingEnabled(),
            'order_number_format' => $config_data->getOrderNumberFormat()
        ];

        $event = new EventArgs([
            'Order' =>  $Order,
            'KomojuConfig'  =>  $komoju_config
        ]);
        // $this->eventDispatcher->dispatch(KomojuEvent::EVENT_KOMOJU_CONFIG_LOAD, $event);
        $this->eventDispatcher->dispatch($event, KomojuEvent::EVENT_KOMOJU_CONFIG_LOAD);
        return $komoju_config;
    }
}