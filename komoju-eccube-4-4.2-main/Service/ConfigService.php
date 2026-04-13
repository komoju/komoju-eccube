<?php

namespace Plugin\Komoju42\Service;

use Doctrine\ORM\EntityManagerInterface;
use Eccube\Repository\PaymentRepository;
use Eccube\Entity\Payment;
use Eccube\Entity\Delivery;
use Eccube\Entity\MailTemplate;
use Eccube\Entity\PaymentOption;
use Eccube\Common\EccubeConfig;
use Plugin\Komoju42\Entity\KomojuPay;
use Plugin\Komoju42\Entity\KomojuConfig;
use Plugin\Komoju42\Entity\KomojuLog;
use Plugin\Komoju42\Service\Method\KomojuMultiPay;
use Plugin\Komoju42\KomojuClient;

class ConfigService{
    protected $eccubeConfig;
    protected $entityManager;

    const MAIL_TEMPLATE_REFUND_REDIRECT = "KOMOJU Refund Notification";

    public function __construct(EntityManagerInterface $entityManager, EccubeConfig $eccubeConfig){
        $this->entityManager = $entityManager;
        $this->eccubeConfig = $eccubeConfig;
    }

    public function enablePlugin(){
        $this->insertMailTemplate();
        $this->createPaymentsForKomojuPays();
    }

    public function disablePlugin(){
        $paymentRepository = $this->entityManager->getRepository(Payment::class);
        $payments = $paymentRepository->findBy(['method_class' => KomojuMultiPay::class]);
        foreach($payments as $Payment){
            $Payment->setVisible(false);
            $this->entityManager->persist($Payment);
        }
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
        $config->setLogRetentionDays(isset($config_data['log_retention_days']) ? $config_data['log_retention_days'] : null);

        $newLoggingEnabled = isset($config_data['logging_enabled']) ? (bool) $config_data['logging_enabled'] : true;
        $oldLoggingEnabled = $config->isLoggingEnabled();
        if ($newLoggingEnabled !== $oldLoggingEnabled) {
            $log = new KomojuLog();
            $log->setApi('config');
            $log->setOrderId('');
            $log->setMsg($newLoggingEnabled ? 'logging enabled' : 'logging disabled');
            $log->setCreatedAt(new \DateTime());
            $this->entityManager->persist($log);
        }

        $config->setLoggingEnabled($newLoggingEnabled);
        $config->setOrderNumberFormat(isset($config_data['order_number_format']) ? $config_data['order_number_format'] : null);

        $this->entityManager->persist($config);
        $this->entityManager->flush();

        // Collect enabled IDs before sync (which may clear the identity map)
        $enabledPayIds = [];
        foreach($config_data['komoju_pays'] as $kp){
            $enabledPayIds[] = $kp->getId();
        }

        $this->syncPaymentMethods($config_data['secret_key']);

        $komoju_pay_repo = $this->entityManager->getRepository(KomojuPay::class);
        $all_komoju_pays = $komoju_pay_repo->findBy([]);
        foreach($all_komoju_pays as $komoju_pay){
            if(in_array($komoju_pay->getId(), $enabledPayIds, true)){
                $komoju_pay->setEnabled(true);
            }else{
                $komoju_pay->setEnabled(false);
            }
            $this->entityManager->persist($komoju_pay);

            $Payment = $komoju_pay->getPayment();
            if($Payment){
                $Payment->setVisible($komoju_pay->isEnabled());
                $this->entityManager->persist($Payment);
            }
        }
        $this->entityManager->flush();
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

        $methods = isset($response['data']) ? $response['data'] : $response;
        if(!is_array($methods)){
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
        foreach($methods as $method){
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
                $Payment = $pay->getPayment();
                if($Payment){
                    $this->removePaymentOptions($Payment);
                    $pay->setPayment(null);
                    $this->entityManager->persist($pay);
                    $this->entityManager->flush();
                    $this->entityManager->remove($Payment);
                }
                $this->entityManager->remove($pay);
            }
        }

        $this->entityManager->flush();

        $this->createPaymentsForKomojuPays();

        return true;
    }
    public function hasPaymentMethods(){
        $komoju_pay_repo = $this->entityManager->getRepository(KomojuPay::class);
        $count = $komoju_pay_repo->findBy([]);
        return !empty($count);
    }
    public function getConfigData($Order = null){
        $komoju_config_repo = $this->entityManager->getRepository(KomojuConfig::class);
        $config = $komoju_config_repo->getConfigByOrder($Order);
        return $config;
    }
    public function createPaymentsForKomojuPays(){
        $komoju_pay_repo = $this->entityManager->getRepository(KomojuPay::class);
        $paymentRepository = $this->entityManager->getRepository(Payment::class);
        $all_komoju_pays = $komoju_pay_repo->findBy([]);

        foreach($all_komoju_pays as $komoju_pay){
            if($komoju_pay->getPayment()){
                $Payment = $komoju_pay->getPayment();
                $Payment->setMethod($komoju_pay->getDispName());
                $Payment->setVisible($komoju_pay->isEnabled());
                $this->entityManager->persist($Payment);
                continue;
            }

            $lastPayment = $paymentRepository->findOneBy([], ['sort_no' => 'DESC']);
            $sortNo = $lastPayment ? $lastPayment->getSortNo() + 1 : 1;

            $Payment = new Payment();
            $Payment->setCharge(0);
            $Payment->setSortNo($sortNo);
            $Payment->setVisible($komoju_pay->isEnabled());
            $Payment->setMethod($komoju_pay->getDispName());
            $Payment->setMethodClass(KomojuMultiPay::class);
            $this->entityManager->persist($Payment);
            $this->entityManager->flush();

            $komoju_pay->setPayment($Payment);
            $this->entityManager->persist($komoju_pay);
            $this->entityManager->flush();

            $this->linkPaymentToDeliveries($Payment);
        }
        $this->entityManager->flush();

        $this->cleanupOrphanedPayments();
    }

    protected function linkPaymentToDeliveries(Payment $Payment){
        $deliveries = $this->entityManager->getRepository(Delivery::class)->findBy(['visible' => true]);
        foreach($deliveries as $Delivery){
            $exists = $this->entityManager->getRepository(PaymentOption::class)->findOneBy([
                'payment_id' => $Payment->getId(),
                'delivery_id' => $Delivery->getId(),
            ]);
            if(!$exists){
                $option = new PaymentOption();
                $option->setPayment($Payment);
                $option->setPaymentId($Payment->getId());
                $option->setDelivery($Delivery);
                $option->setDeliveryId($Delivery->getId());
                $Delivery->addPaymentOption($option);
                $this->entityManager->persist($option);
            }
        }
        $this->entityManager->flush();
    }

    protected function removePaymentOptions(Payment $Payment){
        $options = $this->entityManager->getRepository(PaymentOption::class)->findBy(['payment_id' => $Payment->getId()]);
        foreach($options as $option){
            $this->entityManager->remove($option);
        }
        $this->entityManager->flush();
    }

    private function getLinkedPaymentIds(){
        $linked = [];
        foreach($this->entityManager->getRepository(KomojuPay::class)->findBy([]) as $kp){
            if($kp->getPayment()){
                $linked[$kp->getPayment()->getId()] = true;
            }
        }
        return $linked;
    }

    protected function cleanupOrphanedPayments(){
        $paymentRepository = $this->entityManager->getRepository(Payment::class);
        $linkedPaymentIds = $this->getLinkedPaymentIds();

        // Categorize all KOMOJU payments as active or orphaned (scalars only)
        $activeIdByName = [];
        $orphans = [];
        foreach($paymentRepository->findBy(['method_class' => KomojuMultiPay::class]) as $Payment){
            if(isset($linkedPaymentIds[$Payment->getId()])){
                $activeIdByName[$Payment->getMethod()] = $Payment->getId();
            }else{
                $orphans[] = ['id' => $Payment->getId(), 'method' => $Payment->getMethod()];
            }
        }

        // Phase 1: Migrate orphans that have an active equivalent (pure DQL, no entity state)
        foreach($orphans as $orphan){
            $activeId = isset($activeIdByName[$orphan['method']]) ? $activeIdByName[$orphan['method']] : null;
            if(!$activeId){
                continue;
            }
            $conn = $this->entityManager->getConnection();
            try {
                $conn->beginTransaction();
                $this->entityManager->createQueryBuilder()
                    ->update(\Eccube\Entity\Order::class, 'o')
                    ->set('o.Payment', ':newId')
                    ->where('o.Payment = :oldId')
                    ->setParameter('newId', $activeId)
                    ->setParameter('oldId', $orphan['id'])
                    ->getQuery()
                    ->execute();
                $this->entityManager->createQueryBuilder()
                    ->delete(PaymentOption::class, 'po')
                    ->where('po.payment_id = :pid')
                    ->setParameter('pid', $orphan['id'])
                    ->getQuery()
                    ->execute();
                $this->entityManager->createQueryBuilder()
                    ->delete(Payment::class, 'p')
                    ->where('p.id = :pid')
                    ->setParameter('pid', $orphan['id'])
                    ->getQuery()
                    ->execute();
                $conn->commit();
            } catch (\Exception $e) {
                $conn->rollBack();
                log_warning('KOMOJU: failed to migrate orphaned Payment id=' . $orphan['id'] . ': ' . $e->getMessage());
            }
        }

        // Phase 2: Handle remaining orphans (no active equivalent) — fresh state after DQL
        $this->entityManager->clear();
        $linkedPaymentIds = $this->getLinkedPaymentIds();

        $orderRepo = $this->entityManager->getRepository(\Eccube\Entity\Order::class);
        foreach($paymentRepository->findBy(['method_class' => KomojuMultiPay::class]) as $Payment){
            if(isset($linkedPaymentIds[$Payment->getId()])){
                continue;
            }
            $usedByOrder = $orderRepo->findOneBy(['Payment' => $Payment]);
            if($usedByOrder){
                $Payment->setVisible(false);
                $this->entityManager->persist($Payment);
            }else{
                $this->removePaymentOptions($Payment);
                $this->entityManager->remove($Payment);
            }
        }
        $this->entityManager->flush();
    }
    private function insertMailTemplate(){
        $template_list = [
            [
                'name'      =>  self::MAIL_TEMPLATE_REFUND_REDIRECT,
                'file_name' =>  'Komoju42/Resource/template/mail/refund_redirect.twig',
                'mail_subject'  => trans('komoju_multipay.mail.refund_subject'),
            ],
        ];

    foreach($template_list as $template){
        $template1 = $this->entityManager->getRepository(MailTemplate::class)->findOneBy(["name" => $template["name"]]);
        if ($template1){
            continue;
        }
        $item = new MailTemplate();
        $item->setName($template["name"]);
        $item->setFileName($template["file_name"]);
        $item->setMailSubject($template["mail_subject"]);
        $this->entityManager->persist($item);
        $this->entityManager->flush();
    }
    }
}