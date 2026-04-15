<?php

namespace Plugin\Komoju\Service;

use Doctrine\ORM\EntityManagerInterface;
use Plugin\Komoju\Entity\KomojuOrder;
use Plugin\Komoju\Service\Method\KomojuPayment;
use Plugin\Komoju\Service\ConfigService;
use Plugin\Komoju\Service\KomojuClientFactory;
use Eccube\Entity\Payment;

class KomojuService{

    protected $entityManager;
    protected $config_service;
    protected $client_factory;

    public function __construct(EntityManagerInterface $entityManager, ConfigService $configService, KomojuClientFactory $clientFactory){
        $this->entityManager = $entityManager;
        $this->config_service = $configService;
        $this->client_factory = $clientFactory;
    }

    public function cancelKomojuOrderByOrder($Order){

        $komoju_order = $this->entityManager->getRepository(KomojuOrder::class)->findOneBy(['Order' => $Order]);

        if(empty($komoju_order) || $komoju_order->isCaptured() || $komoju_order->getCanceledAt()){
            return;
        }

        $payment_id = $komoju_order->getKomojuPaymentId();
        $config_data = $this->config_service->getConfigData($Order);
        $komoju_client = $this->client_factory->create($config_data['secret_key']);
        $payment_obj = $komoju_client->getPayment($payment_id);
        if($komoju_client->getStatusCode() != 200 || empty($payment_obj)){
            return;
        }
        if($payment_obj['status'] == "pending" || $payment_obj['status'] == "authorized"){
            $res = $komoju_client->cancelPayment($payment_id);
            $komoju_order->setCanceledAt(new \DateTime());
            $this->entityManager->persist($komoju_order);
            $this->entityManager->flush();
        }
    }
}