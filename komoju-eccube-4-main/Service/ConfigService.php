<?php

namespace Plugin\komoju\Service;

use Symfony\Component\DependencyInjection\ContainerInterface;
use Eccube\Repository\PaymentRepository;
use Eccube\Entity\Payment;
use Eccube\Entity\MailTemplate;
use Eccube\Entity\PaymentOption;
use Eccube\Common\EccubeConfig;
use Plugin\komoju\Entity\KomojuPay;
use Plugin\komoju\Entity\KomojuConfig;
use Plugin\komoju\Service\Method\KomojuMultiPay;
use Plugin\komoju\KomojuClient;

class ConfigService{
    protected $container;
    protected $eccubeConfig;
    protected $entityManager;

    const MAIL_TEMPLATE_REFUND_REDIRECT = "KOMOJU Refund Notification";

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

        $this->syncPaymentMethods($config_data['publishable_key']);

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

    public function syncPaymentMethods($api_key){
        if(empty($api_key)){
            return false;
        }

        $client = new KomojuClient($api_key);
        $response = $client->getPaymentMethods();

        if($client->getStatusCode() !== 200 || empty($response)){
            return false;
        }

        $komoju_pay_repo = $this->entityManager->getRepository(KomojuPay::class);
        $all_existing = $komoju_pay_repo->findBy([]);

        $existing_by_name = [];
        foreach($all_existing as $pay){
            $existing_by_name[$pay->getName()] = $pay;
        }

        $api_slugs = [];
        $sort_no = 1;
        foreach($response as $method){
            $slug = $method['type_slug'];
            $api_slugs[] = $slug;
            $disp_name = !empty($method['name_ja']) ? $method['name_ja'] : $method['name_en'];

            if(isset($existing_by_name[$slug])){
                $pay = $existing_by_name[$slug];
                $pay->setDispName($disp_name);
                $pay->setSortNo($sort_no);
                $this->entityManager->persist($pay);
            }else{
                $pay = new KomojuPay();
                $max_id_result = $this->entityManager->createQueryBuilder()
                    ->select('MAX(p.id)')
                    ->from(KomojuPay::class, 'p')
                    ->getQuery()
                    ->getSingleScalarResult();
                $pay->setId(($max_id_result ? $max_id_result : 0) + 1);
                $pay->setName($slug);
                $pay->setDispName($disp_name);
                $pay->setSortNo($sort_no);
                $pay->setEnabled(false);
                $this->entityManager->persist($pay);
                $this->entityManager->flush();
            }
            $sort_no++;
        }

        foreach($existing_by_name as $name => $pay){
            if(!in_array($name, $api_slugs)){
                $this->entityManager->remove($pay);
            }
        }

        $this->entityManager->flush();
        return true;
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
        $Payment->setMethod(trans('komoju_multipay.shopping.komoju_method_label'));
        $Payment->setMethodClass(KomojuMultiPay::class);
        $this->entityManager->persist($Payment);
        $this->entityManager->flush();
    }
    private function insertMailTemplate(){
        $template_list = [
            [
                'name'      =>  self::MAIL_TEMPLATE_REFUND_REDIRECT,
                'file_name' =>  'komoju/Resource/template/mail/refund_redirect.twig',
                'mail_subject'  => trans('komoju_multipay.mail.refund_subject'),
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