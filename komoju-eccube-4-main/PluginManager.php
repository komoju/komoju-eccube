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
use Plugin\Komoju\Service\Method\KomojuPayment;
use Eccube\Common\EccubeConfig;

class PluginManager extends AbstractPluginManager{

    const BACKUP_ORDER_TABLE = 'plg_komoju_order_backup';
    const BACKUP_CONFIG_TABLE = 'plg_komoju_config_backup';
    const BACKUP_PAYMENTS_TABLE = 'plg_komoju_payments_backup';

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
        $this->restoreBackupData($container);
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
        $this->backupPluginData($container);
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

        $entityManager->persist($config);
        $entityManager->flush();
    }

    private function getConfigService(ContainerInterface $container): ConfigService{
        $entityManager = $container->get('doctrine.orm.entity_manager');
        $eccubeConfig = $container->get(EccubeConfig::class);
        return new ConfigService($entityManager, $eccubeConfig);
    }

    /**
     * Backup plugin tables to non-entity tables before EC-CUBE drops them.
     * These backup tables are not mapped to any Doctrine Entity, so EC-CUBE's
     * schemaService->dropTable() will not touch them.
     */
    private function backupPluginData(ContainerInterface $container){
        $conn = $container->get('doctrine.orm.entity_manager')->getConnection();

        try {
            $this->backupTable($conn, 'plg_komoju_order', self::BACKUP_ORDER_TABLE);
            $this->backupTable($conn, 'plg_komoju_config', self::BACKUP_CONFIG_TABLE);
            $this->backupTable($conn, 'plg_komoju_payments', self::BACKUP_PAYMENTS_TABLE);
        } catch (\Exception $e) {
            log_error('KOMOJU: backup failed during uninstall: ' . $e->getMessage());
        }
    }

    /**
     * Backup a table by creating a new table with safe column types.
     * Uses TEXT/INTEGER only to avoid Doctrine schema introspection errors
     * (e.g., SQLite's NUM type from NUMERIC columns is unrecognized by Doctrine).
     */
    private function backupTable(Connection $conn, string $sourceTable, string $backupTable){
        if (!$this->tableExists($conn, $sourceTable)) {
            return;
        }

        $conn->executeStatement('DROP TABLE IF EXISTS ' . $backupTable);

        $columns = $this->getTableColumns($conn, $sourceTable);
        if (empty($columns)) {
            return;
        }

        // Build CREATE TABLE with safe types: INTEGER for int-like, TEXT for everything else
        $colDefs = [];
        foreach ($columns as $col) {
            $type = strtoupper($col['type']);
            if (preg_match('/INT/', $type)) {
                $colDefs[] = $col['name'] . ' INTEGER';
            } else {
                $colDefs[] = $col['name'] . ' TEXT';
            }
        }

        $conn->executeStatement(
            'CREATE TABLE ' . $backupTable . ' (' . implode(', ', $colDefs) . ')'
        );
        $conn->executeStatement(
            'INSERT INTO ' . $backupTable . ' SELECT * FROM ' . $sourceTable
        );
    }

    /**
     * Get column info from a table, compatible with SQLite and MySQL.
     * Returns array of ['name' => ..., 'type' => ...]
     */
    private function getTableColumns(Connection $conn, string $tableName): array{
        $platform = $conn->getDatabasePlatform();

        if ($platform instanceof \Doctrine\DBAL\Platforms\SqlitePlatform) {
            $rows = $conn->fetchAllAssociative("PRAGMA table_info($tableName)");
            return array_map(function($row) {
                return ['name' => $row['name'], 'type' => $row['type']];
            }, $rows);
        }

        // MySQL / PostgreSQL
        $rows = $conn->fetchAllAssociative(
            "SELECT COLUMN_NAME as name, DATA_TYPE as type FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = ? ORDER BY ORDINAL_POSITION",
            [$tableName]
        );
        return $rows;
    }

    /**
     * Restore backed-up data after re-install and re-link orders to new Payment IDs.
     */
    private function restoreBackupData(ContainerInterface $container){
        $entityManager = $container->get('doctrine.orm.entity_manager');
        $conn = $entityManager->getConnection();

        if (!$this->tableExists($conn, self::BACKUP_ORDER_TABLE)) {
            return;
        }

        try {
            // Restore order data
            $this->restoreOrderData($conn);

            // Restore config data
            $this->restoreConfigData($conn);

            // Re-link old Payment IDs in dtb_order to newly created Payment entities
            $this->relinkOrderPayments($conn);

            // Clean up backup tables
            $conn->executeStatement('DROP TABLE IF EXISTS ' . self::BACKUP_ORDER_TABLE);
            $conn->executeStatement('DROP TABLE IF EXISTS ' . self::BACKUP_CONFIG_TABLE);
            $conn->executeStatement('DROP TABLE IF EXISTS ' . self::BACKUP_PAYMENTS_TABLE);
        } catch (\Exception $e) {
            log_error('KOMOJU: restore failed during enable: ' . $e->getMessage());
        }
    }

    /**
     * Restore plg_komoju_order rows from the backup table.
     *
     * Schema-drift safe: the column set in the backup table reflects whatever
     * schema was active at uninstall time, but the current plg_komoju_order
     * may have added/removed/renamed columns since. We intersect each row's
     * keys with the *current* table's columns before insert; unknown columns
     * are silently dropped, missing columns are left to the DB defaults.
     */
    private function restoreOrderData(Connection $conn){
        if (!$this->tableExists($conn, self::BACKUP_ORDER_TABLE)) {
            return;
        }

        $rows = $conn->fetchAllAssociative('SELECT * FROM ' . self::BACKUP_ORDER_TABLE);
        if (empty($rows)) {
            return;
        }

        $currentColumns = $this->getCurrentColumnNames($conn, 'plg_komoju_order');
        if (empty($currentColumns)) {
            return;
        }
        $currentColumnSet = array_flip($currentColumns);

        foreach ($rows as $row) {
            // Check if this order_id already exists (avoid duplicates)
            $exists = $conn->fetchOne(
                'SELECT COUNT(*) FROM plg_komoju_order WHERE order_id = ?',
                [$row['order_id'] ?? null]
            );
            if ($exists > 0) {
                continue;
            }

            // Remove the 'id' key so the auto-increment generates a new one
            unset($row['id']);

            // Filter to columns that actually exist in the current schema.
            // Drops keys that no longer have a matching column (column rename
            // or drop between plugin versions); missing columns will receive
            // the DB default.
            $filtered = [];
            foreach ($row as $col => $val) {
                if (isset($currentColumnSet[$col])) {
                    $filtered[$col] = $val;
                }
            }
            if (empty($filtered)) {
                continue;
            }
            $conn->insert('plg_komoju_order', $filtered);
        }
    }

    private function restoreConfigData(Connection $conn){
        if (!$this->tableExists($conn, self::BACKUP_CONFIG_TABLE)) {
            return;
        }

        // Only restore if the current config is empty (freshly created)
        $currentConfig = $conn->fetchOne('SELECT secret_key FROM plg_komoju_config WHERE id = 1');
        if (!empty($currentConfig)) {
            return;
        }

        $backup = $conn->fetchAssociative('SELECT * FROM ' . self::BACKUP_CONFIG_TABLE . ' LIMIT 1');
        if (empty($backup)) {
            return;
        }

        unset($backup['id']);

        // Same schema-drift filter as restoreOrderData() above.
        $currentColumns = $this->getCurrentColumnNames($conn, 'plg_komoju_config');
        $currentColumnSet = array_flip($currentColumns);
        $filtered = [];
        foreach ($backup as $col => $val) {
            if (isset($currentColumnSet[$col])) {
                $filtered[$col] = $val;
            }
        }
        if (empty($filtered)) {
            return;
        }
        $conn->update('plg_komoju_config', $filtered, ['id' => 1]);
    }

    private function getCurrentColumnNames(Connection $conn, string $table): array
    {
        try {
            $sm = method_exists($conn, 'createSchemaManager')
                ? $conn->createSchemaManager()
                : $conn->getSchemaManager();
            $columns = $sm->listTableColumns($table);
            return array_keys($columns);
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Re-link dtb_order rows that reference old Komoju Payment IDs to new ones.
     * Uses the backup payments table to map old payment_id -> payment method name,
     * then finds the newly created payment with the same name.
     */
    private function relinkOrderPayments(Connection $conn){
        if (!$this->tableExists($conn, self::BACKUP_PAYMENTS_TABLE)) {
            return;
        }

        // Build mapping: old_payment_id -> method_name from backup
        $oldMappings = $conn->fetchAllAssociative(
            'SELECT payment_id, name FROM ' . self::BACKUP_PAYMENTS_TABLE . ' WHERE payment_id IS NOT NULL'
        );
        if (empty($oldMappings)) {
            return;
        }

        // Build mapping: method_name -> new_payment_id from current plg_komoju_payments
        $newMappings = $conn->fetchAllAssociative(
            'SELECT payment_id, name FROM plg_komoju_payments WHERE payment_id IS NOT NULL'
        );
        $newPaymentByName = [];
        foreach ($newMappings as $row) {
            $newPaymentByName[$row['name']] = $row['payment_id'];
        }

        // For each old payment_id, update orders to point to the new one
        foreach ($oldMappings as $oldMapping) {
            $oldPaymentId = $oldMapping['payment_id'];
            $methodName = $oldMapping['name'];

            if (!isset($newPaymentByName[$methodName])) {
                continue;
            }

            $newPaymentId = $newPaymentByName[$methodName];
            if ($oldPaymentId == $newPaymentId) {
                continue;
            }

            // Update orders referencing the old payment ID
            $conn->executeStatement(
                'UPDATE dtb_order SET payment_id = ? WHERE payment_id = ?',
                [$newPaymentId, $oldPaymentId]
            );
        }

        // Clean up orphaned old Payment records that are no longer referenced
        $allOldPaymentIds = array_column($oldMappings, 'payment_id');
        $allNewPaymentIds = array_values($newPaymentByName);
        $orphanIds = array_diff($allOldPaymentIds, $allNewPaymentIds);

        foreach ($orphanIds as $orphanId) {
            // Only delete if no orders reference it
            $orderCount = $conn->fetchOne(
                'SELECT COUNT(*) FROM dtb_order WHERE payment_id = ?',
                [$orphanId]
            );
            if ($orderCount == 0) {
                $conn->executeStatement('DELETE FROM dtb_payment_option WHERE payment_id = ?', [$orphanId]);
                $conn->executeStatement('DELETE FROM dtb_payment WHERE id = ?', [$orphanId]);
            }
        }
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