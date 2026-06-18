<?php

namespace Plugin\Komoju\Service;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Eccube\Common\EccubeConfig;
use Plugin\Komoju\Service\Method\KomojuPayment;

/**
 * Backup / restore / repair flow for the KOMOJU plugin.
 *
 * Lifecycle context:
 *   - On uninstall, backupBeforeUninstall() copies plg_komoju_* tables to
 *     non-entity backup tables so EC-CUBE's automatic schema drop won't
 *     destroy historical KOMOJU data (which links dtb_order rows to KOMOJU
 *     payment IDs, session IDs, capture/refund state, etc.).
 *   - After re-install + enable, the merchant clicks "支払方法を修復" on the
 *     plugin config page, which invokes repair(). That method restores the
 *     backed-up KOMOJU rows AND re-links dtb_order.payment_id from the
 *     destroyed Payment IDs to the freshly created ones.
 *
 * IMPORTANT: this service must NOT be called from PluginManager::enable().
 * EC-CUBE's PluginService::enable() wraps that call in its own transaction;
 * any query failure here would leave PostgreSQL's transaction aborted
 * (SQLSTATE 25P02), breaking every subsequent query — including EC-CUBE's
 * own PluginRepository::findAllEnabled() — and surface as
 * "システムエラーが発生しました。". The repair flow runs from a normal admin
 * controller action so it has its own request-scoped transaction.
 */
class RepairService
{
    const BACKUP_ORDER_TABLE = 'plg_komoju_order_backup';
    const BACKUP_CONFIG_TABLE = 'plg_komoju_config_backup';
    const BACKUP_PAYMENTS_TABLE = 'plg_komoju_payments_backup';

    /** @var EntityManagerInterface */
    protected $entityManager;
    /** @var EccubeConfig */
    protected $eccubeConfig;

    public function __construct(EntityManagerInterface $entityManager, EccubeConfig $eccubeConfig)
    {
        $this->entityManager = $entityManager;
        $this->eccubeConfig = $eccubeConfig;
    }

    /**
     * Called from PluginManager::uninstall(). Copies KOMOJU tables to backup
     * tables before EC-CUBE drops them.
     */
    public function backupBeforeUninstall(): void
    {
        $conn = $this->entityManager->getConnection();
        try {
            $this->backupTable($conn, 'plg_komoju_order',    self::BACKUP_ORDER_TABLE);
            $this->backupTable($conn, 'plg_komoju_config',   self::BACKUP_CONFIG_TABLE);
            $this->backupTable($conn, 'plg_komoju_payments', self::BACKUP_PAYMENTS_TABLE);
        } catch (\Exception $e) {
            log_error('KOMOJU: backup failed during uninstall: ' . $e->getMessage());
        }
    }

    /**
     * Returns true if there is anything for repair() to do. Used by the config
     * page to decide whether to surface the repair UI.
     */
    public function hasBackupData(): bool
    {
        $conn = $this->entityManager->getConnection();
        return $this->tableExists($conn, self::BACKUP_ORDER_TABLE)
            || $this->tableExists($conn, self::BACKUP_CONFIG_TABLE)
            || $this->tableExists($conn, self::BACKUP_PAYMENTS_TABLE);
    }

    /**
     * Triggered by the admin "支払方法を修復" button.
     *
     * Returns a structured summary so the controller can show the merchant
     * exactly what changed:
     *   [
     *     'orders_restored'      => int,  // rows added to plg_komoju_order
     *     'orders_relinked'      => int,  // dtb_order rows updated
     *     'orphans_deleted'      => int,  // unreferenced dtb_payment rows deleted
     *     'config_restored'      => bool,
     *   ]
     */
    public function repair(): array
    {
        $conn = $this->entityManager->getConnection();
        $summary = [
            'orders_restored' => 0,
            'orders_relinked' => 0,
            'orphans_deleted' => 0,
            'config_restored' => false,
        ];

        if (!$this->tableExists($conn, self::BACKUP_ORDER_TABLE)
            && !$this->tableExists($conn, self::BACKUP_CONFIG_TABLE)
            && !$this->tableExists($conn, self::BACKUP_PAYMENTS_TABLE)) {
            return $summary;
        }

        $summary['orders_restored'] = $this->restoreOrderData($conn);
        $summary['config_restored'] = $this->restoreConfigData($conn);
        list($relinked, $deleted) = $this->relinkOrderPayments($conn);
        $summary['orders_relinked'] = $relinked;
        $summary['orphans_deleted'] = $deleted;

        // Drop backup tables — repair is a one-shot operation.
        $conn->executeStatement('DROP TABLE IF EXISTS ' . self::BACKUP_ORDER_TABLE);
        $conn->executeStatement('DROP TABLE IF EXISTS ' . self::BACKUP_CONFIG_TABLE);
        $conn->executeStatement('DROP TABLE IF EXISTS ' . self::BACKUP_PAYMENTS_TABLE);

        return $summary;
    }

    // ------------------------------------------------------------------
    // backup helpers
    // ------------------------------------------------------------------

    /**
     * Backup a table by creating a new table with safe column types.
     * Uses TEXT/INTEGER only to avoid Doctrine schema introspection errors
     * (e.g., SQLite's NUM type from NUMERIC columns is unrecognized by Doctrine).
     */
    private function backupTable(Connection $conn, string $sourceTable, string $backupTable): void
    {
        if (!$this->tableExists($conn, $sourceTable)) {
            return;
        }

        $conn->executeStatement('DROP TABLE IF EXISTS ' . $backupTable);

        $columns = $this->getTableColumns($conn, $sourceTable);
        if (empty($columns)) {
            return;
        }

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
     * Return [['name' => ..., 'type' => ...], ...] for every column of $tableName.
     *
     * Uses Doctrine's SchemaManager rather than hand-rolled queries against
     * `information_schema` / `PRAGMA table_info`. This matters for two reasons:
     *
     *   1. `INFORMATION_SCHEMA.COLUMNS` on MySQL is global to the connection —
     *      a query without `AND TABLE_SCHEMA = DATABASE()` returns columns
     *      from every database the user can see, which on shared hosting
     *      (multiple DBs per user) corrupts the backup CREATE TABLE. PG and
     *      SQLite scope information_schema to the current database, but
     *      MySQL does not. SchemaManager::listTableColumns() handles this
     *      scoping per backend.
     *
     *   2. The previous SQLite branch interpolated $tableName directly into
     *      a `PRAGMA table_info($tableName)` string. Doctrine's API takes a
     *      typed table name and quotes it correctly per platform.
     *
     * The 'type' string returned here is a Doctrine type name (e.g. 'integer',
     * 'smallint', 'text', 'decimal'). backupTable() only inspects whether the
     * type contains "INT" (case-insensitive) to decide INTEGER vs TEXT, so any
     * of integer/smallint/bigint match correctly.
     */
    private function getTableColumns(Connection $conn, string $tableName): array
    {
        try {
            $sm = method_exists($conn, 'createSchemaManager')
                ? $conn->createSchemaManager()
                : $conn->getSchemaManager();
            $columns = $sm->listTableColumns($tableName);
        } catch (\Exception $e) {
            return [];
        }

        $rows = [];
        foreach ($columns as $col) {
            $rows[] = [
                'name' => $col->getName(),
                'type' => $col->getType()->getName(),
            ];
        }
        return $rows;
    }

    // ------------------------------------------------------------------
    // restore helpers
    // ------------------------------------------------------------------

    /**
     * Restore plg_komoju_order rows from the backup table.
     *
     * Schema-drift safe: the column set in the backup table reflects whatever
     * schema was active at uninstall time, but the current plg_komoju_order
     * may have added/removed/renamed columns since. We intersect the row's
     * keys with the *current* table's columns before insert; unknown columns
     * are silently dropped, missing columns are left to the DB defaults.
     */
    private function restoreOrderData(Connection $conn): int
    {
        if (!$this->tableExists($conn, self::BACKUP_ORDER_TABLE)) {
            return 0;
        }

        $rows = $conn->fetchAllAssociative('SELECT * FROM ' . self::BACKUP_ORDER_TABLE);
        if (empty($rows)) {
            return 0;
        }

        $currentColumns = $this->getCurrentColumnNames($conn, 'plg_komoju_order');
        if (empty($currentColumns)) {
            return 0;
        }
        $currentColumnSet = array_flip($currentColumns);

        $inserted = 0;
        foreach ($rows as $row) {
            // Skip rows we'd duplicate
            $exists = $conn->fetchOne(
                'SELECT COUNT(*) FROM plg_komoju_order WHERE order_id = ?',
                [$row['order_id'] ?? null]
            );
            if ($exists > 0) {
                continue;
            }

            unset($row['id']); // let auto-increment generate a fresh PK

            // Filter to columns that actually exist in the current schema.
            // If a column was dropped between versions we silently discard it
            // rather than failing the whole repair. If a new column was added
            // it'll receive the DB default (likely NULL).
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
            $inserted++;
        }
        return $inserted;
    }

    /**
     * Restore plg_komoju_config from the backup table.
     * Only restores when the current config row is empty (freshly created),
     * so we never overwrite credentials a merchant has already entered.
     */
    private function restoreConfigData(Connection $conn): bool
    {
        if (!$this->tableExists($conn, self::BACKUP_CONFIG_TABLE)) {
            return false;
        }

        $currentSecret = $conn->fetchOne('SELECT secret_key FROM plg_komoju_config WHERE id = 1');
        if (!empty($currentSecret)) {
            return false;
        }

        $backup = $conn->fetchAssociative('SELECT * FROM ' . self::BACKUP_CONFIG_TABLE . ' LIMIT 1');
        if (empty($backup)) {
            return false;
        }

        unset($backup['id']);

        $currentColumns = $this->getCurrentColumnNames($conn, 'plg_komoju_config');
        $currentColumnSet = array_flip($currentColumns);
        $filtered = [];
        foreach ($backup as $col => $val) {
            if (isset($currentColumnSet[$col])) {
                $filtered[$col] = $val;
            }
        }
        if (empty($filtered)) {
            return false;
        }

        $conn->update('plg_komoju_config', $filtered, ['id' => 1]);
        return true;
    }

    /**
     * Re-link dtb_order rows that point at a destroyed Payment ID to the
     * freshly created Payment ID for the same KOMOJU method (matched by
     * stable slug from plg_komoju_payments).
     *
     * Returns [orders_relinked, orphan_payments_deleted].
     */
    private function relinkOrderPayments(Connection $conn): array
    {
        if (!$this->tableExists($conn, self::BACKUP_PAYMENTS_TABLE)) {
            return [0, 0];
        }

        $oldMappings = $conn->fetchAllAssociative(
            'SELECT payment_id, name FROM ' . self::BACKUP_PAYMENTS_TABLE . ' WHERE payment_id IS NOT NULL'
        );
        if (empty($oldMappings)) {
            return [0, 0];
        }

        $newMappings = $conn->fetchAllAssociative(
            'SELECT payment_id, name FROM plg_komoju_payments WHERE payment_id IS NOT NULL'
        );
        $newPaymentByName = [];
        foreach ($newMappings as $row) {
            $newPaymentByName[$row['name']] = $row['payment_id'];
        }

        $relinked = 0;
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
            $relinked += (int)$conn->executeStatement(
                'UPDATE dtb_order SET payment_id = ? WHERE payment_id = ?',
                [$newPaymentId, $oldPaymentId]
            );
        }

        $allOldPaymentIds = array_column($oldMappings, 'payment_id');
        $allNewPaymentIds = array_values($newPaymentByName);
        $orphanIds = array_diff($allOldPaymentIds, $allNewPaymentIds);

        $deleted = 0;
        foreach ($orphanIds as $orphanId) {
            $orderCount = $conn->fetchOne(
                'SELECT COUNT(*) FROM dtb_order WHERE payment_id = ?',
                [$orphanId]
            );
            if ($orderCount == 0) {
                $conn->executeStatement('DELETE FROM dtb_payment_option WHERE payment_id = ?', [$orphanId]);
                $deleted += (int)$conn->executeStatement('DELETE FROM dtb_payment WHERE id = ?', [$orphanId]);
            }
        }
        return [$relinked, $deleted];
    }

    // ------------------------------------------------------------------
    // schema helpers
    // ------------------------------------------------------------------

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

    private function tableExists(Connection $conn, string $tableName): bool
    {
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
