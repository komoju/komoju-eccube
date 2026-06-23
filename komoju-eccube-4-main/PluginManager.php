<?php

namespace Plugin\Komoju;

use Doctrine\DBAL\Connection;
use Eccube\Entity\Payment;
use Eccube\Entity\MailTemplate;
use Eccube\Plugin\AbstractPluginManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Plugin\Komoju\Entity\KomojuConfig;
use Plugin\Komoju\Entity\KomojuPay;
use Plugin\Komoju\Service\ConfigService;
use Plugin\Komoju\Service\RepairService;
use Plugin\Komoju\Service\Method\KomojuPayment;
use Eccube\Common\EccubeConfig;

class PluginManager extends AbstractPluginManager{

    /**
     * Default KOMOJU payment methods seeded on first install.
     *
     * IDs are intentionally NOT specified here. Hard-coding ids (1..N) is
     * fragile because:
     *   - a previous install may have already populated plg_komoju_payments
     *     with those ids and different slugs (e.g. after a sync against the
     *     KOMOJU API returned a renamed method), and
     *   - on a re-install where the table is empty, the schema's id column
     *     still uses GeneratedValue(strategy="NONE") inherited from
     *     AbstractMasterEntity, so we must compute the id ourselves — but we
     *     do it once, in PluginManager::nextPayId(), with a fresh MAX(id)+1
     *     read after each persist, instead of relying on literal magic numbers.
     */
    const DEFAULT_METHODS = [
        ['name' => 'credit_card',   'disp_name' => 'クレジットカード', 'sort_no' => 1],
        ['name' => 'konbini',       'disp_name' => 'コンビニ決済',     'sort_no' => 2],
        ['name' => 'bank_transfer', 'disp_name' => '銀行振込',         'sort_no' => 3],
        ['name' => 'pay_easy',      'disp_name' => 'ペイジー',         'sort_no' => 4],
        ['name' => 'web_money',     'disp_name' => 'ウェブマネー',     'sort_no' => 5],
        ['name' => 'bit_cash',      'disp_name' => 'ビットキャッシュ', 'sort_no' => 6],
        ['name' => 'net_cash',      'disp_name' => 'NET CASH',         'sort_no' => 7],
        ['name' => 'japan_mobile',  'disp_name' => 'キャリア決済',     'sort_no' => 8],
        ['name' => 'paypay',        'disp_name' => 'PayPay',           'sort_no' => 9],
        ['name' => 'linepay',       'disp_name' => 'LINE Pay',         'sort_no' => 10],
        ['name' => 'merpay',        'disp_name' => 'メルペイ',         'sort_no' => 11],
    ];

    /**
     * Tables this plugin must be able to read/write during enable().
     * Used by the pre-flight check to fail fast with a clear error rather than
     * letting a missing table corrupt EC-CUBE's outer transaction mid-way through.
     */
    const REQUIRED_TABLES = [
        'dtb_payment',
        'dtb_payment_option',
        'dtb_order',
        'dtb_mail_template',
        'plg_komoju_payments',
        'plg_komoju_config',
    ];

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
        $entityManager = $container->get('doctrine.orm.entity_manager');
        $method_repo = $entityManager->getRepository(KomojuPay::class);
        foreach(self::DEFAULT_METHODS as $method_data){
            $komoju_pay = $method_repo->findOneBy(['name' => $method_data['name']]);
            if($komoju_pay){
                continue;
            }
            $komoju_pay = new KomojuPay;
            // KomojuPay inherits GeneratedValue(strategy="NONE") from
            // AbstractMasterEntity, so we must assign the id ourselves. We
            // compute MAX(id)+1 fresh per row; this is single-threaded by
            // construction (registerMethods runs only inside the plugin
            // install/enable lifecycle, never concurrently with itself).
            $komoju_pay->setId(self::nextPayId($entityManager));
            $komoju_pay->setName($method_data['name']);
            $komoju_pay->setDispName($method_data['disp_name']);
            $komoju_pay->setSortNo($method_data['sort_no']);
            $komoju_pay->setEnabled(true);
            $entityManager->persist($komoju_pay);
            $entityManager->flush();
        }
    }

    /**
     * Return the next available KomojuPay id (MAX(id) + 1, or 1 if empty).
     *
     * KomojuPay extends Eccube\Entity\Master\AbstractMasterEntity which uses
     * Doctrine GeneratedValue(strategy="NONE"); the database does NOT
     * auto-assign ids, so the caller must provide one before persist().
     *
     * Centralising the computation here removes the two former duplicates
     * (hard-coded 1..11 in registerMethods, MAX(id)+1 inline in
     * ConfigService::syncPaymentMethods) and gives one place to upgrade if
     * we later switch to a real IDENTITY column.
     */
    public static function nextPayId(\Doctrine\ORM\EntityManagerInterface $em): int
    {
        $max = $em->createQueryBuilder()
            ->select('MAX(p.id)')
            ->from(KomojuPay::class, 'p')
            ->getQuery()
            ->getSingleScalarResult();
        return ((int)$max) + 1;
    }

    public function enable(array $meta, ContainerInterface $container){
        // Pre-flight: fail fast if the environment isn't in the expected shape.
        // This runs before any mutations so a failure here cannot poison
        // EC-CUBE's outer transaction (PluginService::enable wraps this method
        // in beginTransaction()/commit()).
        $this->validateEnvironment($container);

        $this->createConfig($container);
        $this->registerMethods($container);
        $this->insertMailTemplate($container);
        $this->getConfigService($container)->createPaymentsForKomojuPays();

        // NOTE: backup/restore of historical KOMOJU data after an
        // uninstall/reinstall is now an opt-in action triggered from the plugin
        // settings page (see RepairService::repair()). It is intentionally NOT
        // run here:
        //   1. It mutates dtb_order and deletes from dtb_payment, which is too
        //      heavy for the silent plugin-enable path.
        //   2. Any failure inside enable() runs inside EC-CUBE's outer
        //      transaction; on PostgreSQL, a failed query aborts the whole
        //      transaction and breaks the request even with a try/catch.
        //   3. Most enables don't need it. Merchants who do need it will be
        //      pointed at the "支払方法を修復" button on the settings page.
    }

    public function disable(array $meta, ContainerInterface $container){
        $entityManager = $container->get('doctrine.orm.entity_manager');
        $paymentRepository = $entityManager->getRepository(Payment::class);
        $payments = $paymentRepository->findBy(['method_class' => KomojuPayment::class]);
        foreach($payments as $Payment){
            $Payment->setVisible(false);
            $entityManager->persist($Payment);
        }
        $entityManager->flush();
    }

    public function uninstall(array $meta, ContainerInterface $container){
        $repair = new RepairService(
            $container->get('doctrine.orm.entity_manager'),
            $container->get(EccubeConfig::class)
        );
        $repair->backupBeforeUninstall();
    }

    /**
     * Verify that every table this plugin needs to operate on exists.
     *
     * On PostgreSQL, querying a missing relation aborts the surrounding
     * transaction (SQLSTATE 25P02). Because PluginService::enable() wraps this
     * method in its own transaction, even a caught exception poisons the
     * connection for every later query in the request — including EC-CUBE's
     * own findAllEnabled() in regenerateProxy(). To avoid that, we use
     * Doctrine SchemaManager metadata (information_schema / sqlite_master),
     * which never executes a statement against the missing table.
     *
     * If anything is missing we throw with a Japanese-friendly message; the
     * caller (PluginService) will roll back cleanly and the merchant sees a
     * comprehensible error in the admin UI.
     */
    private function validateEnvironment(ContainerInterface $container){
        $conn = $container->get('doctrine.orm.entity_manager')->getConnection();
        $missing = [];
        foreach(self::REQUIRED_TABLES as $table){
            if(!$this->tableExists($conn, $table)){
                $missing[] = $table;
            }
        }
        if(!empty($missing)){
            throw new \RuntimeException(
                'KOMOJU: required tables are missing — ' . implode(', ', $missing)
                . '. Run schema update before enabling the plugin.'
            );
        }
    }

    protected function insertMailTemplate(ContainerInterface $container){
        $entityManager = $container->get('doctrine.orm.entity_manager');
        $template = $entityManager->getRepository(MailTemplate::class)->findOneBy(["name" => "KOMOJU Refund Notification"]);
        if($template){
            return;
        }
        $item = new MailTemplate();
        $item->setName("KOMOJU Refund Notification");
        $item->setFileName('Komoju/Resource/template/mail/refund_redirect.twig');
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
        $config->setOrderNumberFormat(KomojuConfig::DEFAULT_ORDER_NUMBER_FORMAT);

        $entityManager->persist($config);
        $entityManager->flush();
    }

    private function getConfigService(ContainerInterface $container): ConfigService{
        $entityManager = $container->get('doctrine.orm.entity_manager');
        $eccubeConfig = $container->get(EccubeConfig::class);
        return new ConfigService($entityManager, $eccubeConfig);
    }

    /**
     * Check whether a table exists, without executing any statement that would
     * error on the live connection.
     *
     * IMPORTANT: a `SELECT 1 FROM <table>` style probe must NOT be used here.
     * On PostgreSQL, a query against a missing relation aborts the surrounding
     * transaction (SQLSTATE 25P02), and because EC-CUBE's PluginService::enable()
     * runs PluginManager::enable() inside its own outer transaction, our PHP-level
     * try/catch cannot recover that transaction. Every later query — including
     * EC-CUBE's own PluginRepository::findAllEnabled() during regenerateProxy() —
     * would then fail with `current transaction is aborted, commands ignored
     * until end of transaction block` and the user sees "システムエラーが発生しました。".
     *
     * Doctrine's SchemaManager::tablesExist() reads metadata (information_schema /
     * sqlite_master) without ever executing a statement against the missing table,
     * so it is safe inside an outer transaction on PostgreSQL, MySQL and SQLite.
     */
    private function tableExists(Connection $conn, string $tableName): bool{
        try {
            $sm = method_exists($conn, 'createSchemaManager')
                ? $conn->createSchemaManager()
                : $conn->getSchemaManager();
            return $sm->tablesExist([$tableName]);
        } catch (\Exception $e) {
            return false;
        }
    }
}
