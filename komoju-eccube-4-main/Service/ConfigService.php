<?php

namespace Plugin\Komoju\Service;

use Doctrine\ORM\EntityManagerInterface;
use Eccube\Repository\PaymentRepository;
use Eccube\Entity\Payment;
use Eccube\Entity\Delivery;
use Eccube\Entity\MailTemplate;
use Eccube\Entity\PaymentOption;
use Eccube\Common\EccubeConfig;
use Plugin\Komoju\Entity\KomojuPay;
use Plugin\Komoju\Entity\KomojuConfig;
use Plugin\Komoju\Entity\KomojuLog;
use Plugin\Komoju\Service\Method\KomojuPayment;
use Plugin\Komoju\Service\KomojuClientFactory;

class ConfigService{
    protected $eccubeConfig;
    protected $entityManager;
    protected $client_factory;

    const MAIL_TEMPLATE_REFUND_REDIRECT = "KOMOJU Refund Notification";

    public function __construct(EntityManagerInterface $entityManager, EccubeConfig $eccubeConfig, ?KomojuClientFactory $clientFactory = null){
        $this->entityManager = $entityManager;
        $this->eccubeConfig = $eccubeConfig;
        $this->client_factory = $clientFactory ?: new KomojuClientFactory();
    }

    public function enablePlugin(){
        $this->insertMailTemplate();
        $this->createPaymentsForKomojuPays();
    }

    public function disablePlugin(){
        $paymentRepository = $this->entityManager->getRepository(Payment::class);
        $payments = $paymentRepository->findBy(['method_class' => KomojuPayment::class]);
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

        $this->syncPaymentMethods($config_data['secret_key']);
    }

    public function syncPaymentMethods($api_key){
        if(empty($api_key)){
            return false;
        }

        $client = $this->client_factory->create($api_key);
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
                // KomojuPay inherits GeneratedValue(strategy="NONE") from
                // AbstractMasterEntity — the DB does not auto-assign ids —
                // so we must compute one. Delegated to PluginManager::nextPayId
                // so this lives in exactly one place.
                $pay = new KomojuPay();
                $pay->setId(\Plugin\Komoju\PluginManager::nextPayId($this->entityManager));
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
            $Payment->setMethodClass(KomojuPayment::class);
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

        // Build reverse lookup: Payment ID → KomojuPay.name (stable slug)
        $slugByPaymentId = [];
        foreach($this->entityManager->getRepository(KomojuPay::class)->findBy([]) as $kp){
            if($kp->getPayment()){
                $slugByPaymentId[$kp->getPayment()->getId()] = $kp->getName();
            }
        }

        // Categorize all KOMOJU payments as active or orphaned (scalars only)
        $activeIdBySlug = [];
        $orphans = [];
        foreach($paymentRepository->findBy(['method_class' => KomojuPayment::class]) as $Payment){
            $slug = isset($slugByPaymentId[$Payment->getId()]) ? $slugByPaymentId[$Payment->getId()] : $Payment->getMethod();
            if(isset($linkedPaymentIds[$Payment->getId()])){
                $activeIdBySlug[$slug] = $Payment->getId();
            }else{
                $orphans[] = ['id' => $Payment->getId(), 'slug' => $slug];
            }
        }

        // Phase 1: Migrate orphans that have an active equivalent (pure DQL, no entity state).
        //
        // NOTE: do NOT wrap each orphan in beginTransaction()/commit()/rollBack().
        // EC-CUBE's PluginService already opens an outer transaction around the
        // enable/disable/save flows that call this method, so a nested
        // beginTransaction() in DBAL just increments a refcount without issuing
        // a real SAVEPOINT. On PostgreSQL, if any inner query fails the outer
        // transaction is left in an aborted state and a subsequent rollBack()
        // here only decrements the counter — every later query in the request,
        // including EC-CUBE's own PluginRepository::findAllEnabled() inside
        // regenerateProxy(), then fails with SQLSTATE 25P02. We let exceptions
        // bubble up so the outer transaction can be rolled back cleanly by the
        // caller.
        foreach($orphans as $orphan){
            $activeId = isset($activeIdBySlug[$orphan['slug']]) ? $activeIdBySlug[$orphan['slug']] : null;
            if(!$activeId){
                continue;
            }
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
        }

        // Phase 2: Handle remaining orphans (no active equivalent) using DQL
        // Re-query linked IDs from DB to get fresh state after Phase 1 DQL deletes
        $linkedIds = $this->entityManager->createQueryBuilder()
            ->select('IDENTITY(kp.Payment)')
            ->from(KomojuPay::class, 'kp')
            ->where('kp.Payment IS NOT NULL')
            ->getQuery()
            ->getSingleColumnResult();
        $linkedIdSet = array_flip($linkedIds);

        $remainingPayments = $this->entityManager->createQueryBuilder()
            ->select('p.id')
            ->from(Payment::class, 'p')
            ->where('p.method_class = :mc')
            ->setParameter('mc', KomojuPayment::class)
            ->getQuery()
            ->getArrayResult();

        foreach($remainingPayments as $row){
            $pid = $row['id'];
            if(isset($linkedIdSet[$pid])){
                continue;
            }
            $usedByOrder = $this->entityManager->createQueryBuilder()
                ->select('COUNT(o.id)')
                ->from(\Eccube\Entity\Order::class, 'o')
                ->where('o.Payment = :pid')
                ->setParameter('pid', $pid)
                ->getQuery()
                ->getSingleScalarResult();
            if($usedByOrder > 0){
                $this->entityManager->createQueryBuilder()
                    ->update(Payment::class, 'p')
                    ->set('p.visible', ':vis')
                    ->where('p.id = :pid')
                    ->setParameter('vis', false)
                    ->setParameter('pid', $pid)
                    ->getQuery()
                    ->execute();
            }else{
                $this->entityManager->createQueryBuilder()
                    ->delete(PaymentOption::class, 'po')
                    ->where('po.payment_id = :pid')
                    ->setParameter('pid', $pid)
                    ->getQuery()
                    ->execute();
                $this->entityManager->createQueryBuilder()
                    ->delete(Payment::class, 'p')
                    ->where('p.id = :pid')
                    ->setParameter('pid', $pid)
                    ->getQuery()
                    ->execute();
            }
        }
    }
    private function insertMailTemplate(){
        $template_list = [
            [
                'name'      =>  self::MAIL_TEMPLATE_REFUND_REDIRECT,
                'file_name' =>  'Komoju/Resource/template/mail/refund_redirect.twig',
                'mail_subject'  => trans('komoju_payment.mail.refund_subject'),
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