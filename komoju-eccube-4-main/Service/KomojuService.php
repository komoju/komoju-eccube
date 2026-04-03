<?php

namespace Plugin\komoju\Service;

use Symfony\Component\DependencyInjection\ContainerInterface;
use Plugin\komoju\Entity\KomojuOrder;
use Plugin\komoju\KomojuClient;
use Plugin\komoju\Service\Method\KomojuMultiPay;
use Eccube\Entity\Payment;

class KomojuService{

    protected $container;
    protected $entityManager;

    public function __construct(ContainerInterface $container){
        $this->container = $container;
        $this->entityManager = $container->get('doctrine.orm.entity_manager');
    }

    public function cancelKomojuOrderByOrder($Order){
        
        $komoju_order = $this->entityManager->getRepository(KomojuOrder::class)->findOneBy(['Order' => $Order]);

        if(empty($komoju_order) || $komoju_order->isCaptured() || $komoju_order->getCanceledAt()){
            return;
        }

        $payment_id = $komoju_order->getKomojuPaymentId();
        $config_service = $this->container->get("plg_komoju.service.config");
        $config_data = $config_service->getConfigData($Order);
        $komoju_client = new KomojuClient($config_data['secret_key']);
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