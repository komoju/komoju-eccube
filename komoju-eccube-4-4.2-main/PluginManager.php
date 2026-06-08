<?php

namespace Plugin\Komoju42;

use Doctrine\DBAL\Connection;
use Eccube\Entity\Payment;
use Eccube\Entity\MailTemplate;
use Eccube\Plugin\AbstractPluginManager;
use Psr\Container\ContainerInterface;
use Plugin\Komoju42\Entity\KomojuConfig;
use Plugin\Komoju42\Entity\KomojuPay;
use Plugin\Komoju42\Service\ConfigService;
use Plugin\Komoju42\Service\Method\KomojuPayment;
use Eccube\Common\EccubeConfig;

class PluginManager extends AbstractPluginManager{

    const BACKUP_ORDER_TABLE = 'plg_komoju_order_backup';
    const BACKUP_CONFIG_TABLE = 'plg_komoju_config_backup';
    const BACKUP_PAYMENTS_TABLE = 'plg_komoju_payments_backup';

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
        $this->restoreBackupData($container);
        $this->consolidateOrphanedPayments($container);
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

    private function restoreOrderData(Connection $conn){
        if (!$this->tableExists($conn, self::BACKUP_ORDER_TABLE)) {
            return;
        }

        $rows = $conn->fetchAllAssociative('SELECT * FROM ' . self::BACKUP_ORDER_TABLE);
        if (empty($rows)) {
            return;
        }

        foreach ($rows as $row) {
            // Check if this order_id already exists (avoid duplicates)
            $exists = $conn->fetchOne(
                'SELECT COUNT(*) FROM plg_komoju_order WHERE order_id = ?',
                [$row['order_id']]
            );
            if ($exists > 0) {
                continue;
            }

            // Remove the 'id' key so the auto-increment generates a new one
            unset($row['id']);
            $conn->insert('plg_komoju_order', $row);
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
        $conn->update('plg_komoju_config', $backup, ['id' => 1]);
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
     * Migrate orders from orphaned Komoju Payment records to current active ones.
     * This handles leftovers from previous installs where the plugin was uninstalled
     * without the backup/restore mechanism.
     */
    private function consolidateOrphanedPayments(ContainerInterface $container){
        $conn = $container->get('doctrine.orm.entity_manager')->getConnection();

        try {
            // Get current active payment IDs (linked from plg_komoju_payments)
            $activePayments = $conn->fetchAllAssociative(
                'SELECT payment_id, name FROM plg_komoju_payments WHERE payment_id IS NOT NULL'
            );
            if (empty($activePayments)) {
                return;
            }

            $activeByName = [];
            $activeIds = [];
            foreach ($activePayments as $row) {
                $activeByName[$row['name']] = $row['payment_id'];
                $activeIds[] = $row['payment_id'];
            }

            // Find all Komoju payment records NOT in the active set
            $allKomoju = $conn->fetchAllAssociative(
                "SELECT id, payment_method FROM dtb_payment WHERE method_class = ? AND id NOT IN (" . implode(',', $activeIds) . ")",
                [KomojuPayment::class]
            );

            foreach ($allKomoju as $orphan) {
                $orphanId = $orphan['id'];
                $methodName = $orphan['payment_method'];

                // Try to find the matching active payment by display name
                $newId = null;
                foreach ($activePayments as $active) {
                    $activePaymentName = $conn->fetchOne(
                        'SELECT payment_method FROM dtb_payment WHERE id = ?',
                        [$active['payment_id']]
                    );
                    if ($activePaymentName === $methodName) {
                        $newId = $active['payment_id'];
                        break;
                    }
                }

                if ($newId && $newId != $orphanId) {
                    // Migrate orders to the active payment ID
                    $conn->executeStatement(
                        'UPDATE dtb_order SET payment_id = ? WHERE payment_id = ?',
                        [$newId, $orphanId]
                    );
                }

                // Delete orphan if no longer referenced
                $orderCount = $conn->fetchOne(
                    'SELECT COUNT(*) FROM dtb_order WHERE payment_id = ?',
                    [$orphanId]
                );
                if ($orderCount == 0) {
                    $conn->executeStatement('DELETE FROM dtb_payment_option WHERE payment_id = ?', [$orphanId]);
                    $conn->executeStatement('DELETE FROM dtb_payment WHERE id = ?', [$orphanId]);
                }
            }
        } catch (\Exception $e) {
            log_error('KOMOJU: consolidate orphaned payments failed: ' . $e->getMessage());
        }
    }

    private function tableExists(Connection $conn, string $tableName): bool{
        try {
            $conn->fetchOne('SELECT 1 FROM ' . $tableName . ' LIMIT 1');
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}