<?php

namespace Plugin\Komoju42;

use Eccube\Entity\Payment;
use Eccube\Entity\MailTemplate;
use Eccube\Plugin\AbstractPluginManager;
use Psr\Container\ContainerInterface;
use Plugin\Komoju42\Entity\KomojuConfig;
use Plugin\Komoju42\Entity\KomojuPay;
use Plugin\Komoju42\Service\ConfigService;
use Eccube\Common\EccubeConfig;

class PluginManager extends AbstractPluginManager{

    /**
     * プラグインアップデート時の処理
     *
     * @param array              $meta
     * @param ContainerInterface $container
     */
    public function update(array $meta, ContainerInterface $container)
    {
        try{
            $entityManager = $container->get('doctrine')->getManager();
            if(\method_exists($this, 'migration')){
                $this->migration($entityManager->getConnection(), $meta['code']);
            }
            $this->registerMethods($container);
            $this->getConfigService($container)->createPaymentsForKomojuPays();
        }catch(\Exception $e){
            log_error('KOMOJU plugin update failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * 支払方法を登録
     *
     * @param ContainerInterface $container
     */
    protected function registerMethods(ContainerInterface $container){
        $methods_arr = [
            [
                'id'        =>  1,
                'name'      =>  'credit_card'   ,
                'disp_name' =>  'クレジットカード',
                'sort_no'   =>  1,
            ],
            [
                'id'        =>  2,
                'name'      =>  'konbini'   ,
                'disp_name' =>  'コンビニ決済',
                'sort_no'   =>  2,
            ],
            [
                'id'        =>  3,
                'name'      =>  'bank_transfer'   ,
                'disp_name' =>  '銀行振込',
                'sort_no'   =>  3,
            ],
            [
                'id'        =>  4,
                'name'      =>  'pay_easy'   ,
                'disp_name' =>  'ペイジー',
                'sort_no'   =>  4,
            ],
            [
                'id'        =>  5,
                'name'      =>  'web_money'   ,
                'disp_name' =>  'ウェブマネー',
                'sort_no'   =>  5,
            ],
            [
                'id'        =>  6,
                'name'      =>  'bit_cash'   ,
                'disp_name' =>  'ビットキャッシュ',
                'sort_no'   =>  6,
            ],
            [
                'id'        =>  7,
                'name'      =>  'net_cash'   ,
                'disp_name' =>  'NET CASH',
                'sort_no'   =>  7,
            ],
            [
                'id'        =>  8,
                'name'      =>  'japan_mobile'   ,
                'disp_name' =>  'キャリア決済',
                'sort_no'   =>  8,
            ],
            [
                'id'        =>  9,
                'name'      =>  'paypay'   ,
                'disp_name' =>  'PayPay',
                'sort_no'   =>  9,
            ],
            [
                'id'        =>  10,
                'name'      =>  'linepay'   ,
                'disp_name' =>  'LINE Pay',
                'sort_no'   =>  10,
            ],
            [
                'id'        =>  11,
                'name'      =>  'merpay'   ,
                'disp_name' =>  'メルペイ',
                'sort_no'   =>  11,
            ],
        ];
        $entityManager = $container->get('doctrine.orm.entity_manager');
        $method_repo = $entityManager->getRepository(KomojuPay::class);
        foreach($methods_arr as $method_data){
            $komoju_pay = $method_repo->findOneBy(['name' => $method_data['name']]);
            if($komoju_pay){
                continue;
            }
            $komoju_pay = new KomojuPay;
            $komoju_pay->setId($method_data['id']);
            $komoju_pay->setName($method_data['name']);
            $komoju_pay->setDispName($method_data['disp_name']);
            $komoju_pay->setSortNo($method_data['sort_no']);
            $komoju_pay->setEnabled(true);
            $entityManager->persist($komoju_pay);
            $entityManager->flush();
        }
    }

    public function enable(array $meta, ContainerInterface $container){
        $this->createConfig($container);
        $this->registerMethods($container);
        $this->insertMailTemplate($container);
        $this->getConfigService($container)->createPaymentsForKomojuPays();
    }

    public function disable(array $meta, ContainerInterface $container){
        $entityManager = $container->get('doctrine.orm.entity_manager');
        $paymentRepository = $entityManager->getRepository(Payment::class);
        $payments = $paymentRepository->findBy(['method_class' => \Plugin\Komoju42\Service\Method\KomojuPayment::class]);
        foreach($payments as $Payment){
            $Payment->setVisible(false);
            $entityManager->persist($Payment);
        }
        $entityManager->flush();
    }

    protected function insertMailTemplate(ContainerInterface $container){
        $entityManager = $container->get('doctrine.orm.entity_manager');
        $template = $entityManager->getRepository(MailTemplate::class)->findOneBy(["name" => "KOMOJU Refund Notification"]);
        if($template){
            return;
        }
        $item = new MailTemplate();
        $item->setName("KOMOJU Refund Notification");
        $item->setFileName('Komoju42/Resource/template/mail/refund_redirect.twig');
        $item->setMailSubject(trans('komoju_payment.mail.refund_subject'));
        $entityManager->persist($item);
        $entityManager->flush();
    }

    private function createConfig(ContainerInterface $container){
        $entityManager = $container->get('doctrine.orm.entity_manager');
        $config = $entityManager->find(KomojuConfig::class, 1);
        if($config){
            return;
        }
        $config = new KomojuConfig();
        $config->setPublishableKey('');
        $config->setSecretKey('');
        $config->setMerchantUuid('');
        $config->setWebhookSecret(bin2hex(random_bytes(32)));

        $entityManager->persist($config);
        $entityManager->flush();
    }

    private function getConfigService(ContainerInterface $container): ConfigService{
        $entityManager = $container->get('doctrine.orm.entity_manager');
        $eccubeConfig = $container->get(EccubeConfig::class);
        return new ConfigService($entityManager, $eccubeConfig);
    }
}