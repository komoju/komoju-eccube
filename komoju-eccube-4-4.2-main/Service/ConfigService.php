<?php

namespace Plugin\komoju42\Service;

use Psr\Container\ContainerInterface;
use Eccube\Repository\PaymentRepository;
use Eccube\Entity\Payment;
use Eccube\Entity\MailTemplate;
use Eccube\Entity\PaymentOption;
use Eccube\Common\EccubeConfig;
use Plugin\komoju42\Entity\KomojuPay;
use Plugin\komoju42\Entity\KomojuConfig;
use Plugin\komoju42\Service\Method\KomojuMultiPay;

class ConfigService{
    protected $container;
    protected $eccubeConfig;
    protected $entityManager;

    const MAIL_TEMPLATE_REFUND_REDIRECT = "Komoju返金メール";

    public function __construct(ContainerInterface $container, EccubeConfig $eccubeConfig){
        $this->container = $container;
        $this->eccubeConfig = $eccubeConfig;
        $this->entityManager = $container->get('doctrine.orm.entity_manager');
            
    }
    
    public function enablePlugin(){
        $this->createTokenPayment();
        $this->insertMailTemplate();
    }

    public function disablePlugin(){
        $paymentRepository = $this->entityManager->getRepository(Payment::class);
        $Payment = $paymentRepository->findOneBy(['method_class' => KomojuMultiPay::class]);
        if(empty($Payment)){
            return;
        }
        $Payment->setVisible(false);
        $this->entityManager->persist($Payment);
        $this->entityManager->flush();
    }

    public function saveConfig($config_data){
        $config_repo = $this->entityManager->getRepository(KomojuConfig::class);
        $config = $config_repo->get();
        if(empty($config)){
            $config = new KomojuConfig;
        }
        $config->setPublishableKey($config_data['publishable_key']);
        $config->setSecretKey($config_data['secret_key']);
        $config->setMerchantUuid($config_data['merchant_uuid']);
        $config->setWebhookSecret($config_data['webhook_secret']);
        $config->setCaptureOn( isset($config_data['capture_on']) ? $config_data['capture_on'] : true);
        
        $this->entityManager->persist($config);
        $this->entityManager->flush();

        $komoju_pays = $config_data['komoju_pays'];
        $komoju_pay_repo = $this->entityManager->getRepository(KomojuPay::class);        
        $all_komoju_pays = $komoju_pay_repo->findBy([]);        
        foreach($all_komoju_pays as $komoju_pay){
            if($komoju_pays->contains($komoju_pay)){
                $komoju_pay->setEnabled(true);
                $this->entityManager->persist($komoju_pay);
            }else{
                $komoju_pay->setEnabled(false);
                $this->entityManager->persist($komoju_pay);
            }
            $this->entityManager->flush();
        }
        return;
    }
    public function getConfigData($Order = null){
        $komoju_config_repo = $this->entityManager->getRepository(KomojuConfig::class);    
        $config = $komoju_config_repo->getConfigByOrder($Order);        
        return $config;
    }
    // ===============for enablePlugin===========
    protected function createTokenPayment(){
        $paymentRepository = $this->entityManager->getRepository(Payment::class);
        $Payment = $paymentRepository->findOneBy(['method_class' => KomojuMultiPay::class]);
        if($Payment){
            return;
        }
        $lastPayment = $paymentRepository->findOneBy([], ['sort_no' => 'DESC']);
        $sortNo = $lastPayment ? $lastPayment->getSortNo() + 1 : 1;
        $Payment = new Payment();
        $Payment->setCharge(0);
        $Payment->setSortNo($sortNo);
        $Payment->setVisible(true);
        $Payment->setMethod('マルチ決済');
        $Payment->setMethodClass(KomojuMultiPay::class);
        $this->entityManager->persist($Payment);
        $this->entityManager->flush();
    }
    private function insertMailTemplate(){
        $template_list = [
            [
                'name'      =>  self::MAIL_TEMPLATE_REFUND_REDIRECT,
                'file_name' =>  'komoju42/Resource/template/mail/refund_redirect.twig',
                'mail_subject'  => 'Komoju返金メール',
            ],
        ];

    $em = $this->container->get('doctrine.orm.entity_manager');
    foreach($template_list as $template){
        $template1 = $em->getRepository(MailTemplate::class)->findOneBy(["name" => $template["name"]]);
        if ($template1){
            continue;
        }
        $item = new MailTemplate();
        $item->setName($template["name"]);
        $item->setFileName($template["file_name"]);
        $item->setMailSubject($template["mail_subject"]);
        $em->persist($item);            
        $em->flush();
    }
    }
}