<?php

namespace Plugin\Komoju42\Service;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Eccube\Common\EccubeConfig;
use Plugin\Komoju42\Service\Method\KomojuPayment;

/**
 * Backup / restore / repair flow for the KOMOJU plugin.
 *
 * On uninstall, backupBeforeUninstall() copies plg_komoju_* tables to backup
 * tables so EC-CUBE's schema drop won't destroy historical KOMOJU data. After
 * reinstall, the admin "支払方法を修復" button invokes repair(), which restores
 * those rows and re-links dtb_order.payment_id to the freshly created Payments.
 *
 * MUST NOT be called from PluginManager::enable(): EC-CUBE wraps enable() in
 * a transaction, and any query failure here would abort it on PostgreSQL
 * (SQLSTATE 25P02), breaking every later query in the request. Repair runs
 * from a normal admin controller so it has its own request-scoped transaction.
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
     * Triggered by the admin "支払方法を修復" button. Returns a summary:
     *   orders_restored (int), orders_relinked (int),
     *   orphans_deleted (int), config_restored (bool).
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
     * Create a backup table using only TEXT/INTEGER types, to dodge Doctrine
     * introspection errors (e.g. SQLite NUM from NUMERIC columns is unknown).
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
     * Return [['name' => ..., 'type' => ...], ...] for $tableName.
     *
     * Uses Doctrine SchemaManager rather than raw information_schema / PRAGMA:
     *   - MySQL's information_schema is not auto-scoped to the current DB,
     *     which corrupts backups on shared hosting with multiple DBs.
     *   - Avoids interpolating table names into PRAGMA table_info(...).
     *
     * 'type' is a Doctrine type name (integer/smallint/bigint/text/decimal/...);
     * backupTable() just checks for "INT" substring to pick INTEGER vs TEXT.
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
     * Restore plg_komoju_order rows from backup.
     *
     * Schema-drift safe: intersect each row's keys with the current table's
     * columns before insert. Dropped columns are silently discarded; new
     * columns get the DB default.
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

        // Assign primary keys explicitly (predictable, contiguous, portable).
        // Doctrine DOES back this column with a sequence on PostgreSQL via
        // @GeneratedValue(strategy="AUTO"), but explicit ids on PG don't
        // advance it — see resyncDoctrineSequence() below for the fix.
        $nextId = ((int) $conn->fetchOne('SELECT MAX(id) FROM plg_komoju_order')) + 1;

        $inserted = 0;
        foreach ($rows as $row) {
            // Skip rows we'd duplicate.
            $exists = $conn->fetchOne(
                'SELECT COUNT(*) FROM plg_komoju_order WHERE order_id = ?',
                [$row['order_id'] ?? null]
            );
            if ($exists > 0) {
                continue;
            }

            // Drop columns that no longer exist in the current schema.
            $filtered = [];
            foreach ($row as $col => $val) {
                if (isset($currentColumnSet[$col])) {
                    $filtered[$col] = $val;
                }
            }
            if (empty($filtered)) {
                continue;
            }

            $filtered['id'] = $nextId++;
            $conn->insert('plg_komoju_order', $filtered);
            $inserted++;
        }

        // Explicit-id inserts bypass the PG sequence; resync or the next ORM
        // INSERT will collide. No-op on MySQL/SQLite (they auto-advance).
        if ($inserted > 0) {
            $this->resyncDoctrineSequence($conn, 'plg_komoju_order', 'plg_komoju_order_id_seq', 'id');
        }

        return $inserted;
    }

    /**
     * Restore plg_komoju_config from backup. Skipped if the current config
     * already has a secret_key, so merchant-entered credentials aren't lost.
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
     * Re-link dtb_order rows from destroyed Payment IDs to the freshly
     * created ones, matched by stable slug in plg_komoju_payments.
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

    /**
     * Resync the Doctrine-managed sequence to MAX(<column>) on PostgreSQL.
     * Needed because explicit-id INSERTs don't advance the sequence, so the
     * next ORM-driven INSERT would collide on the primary key.
     *
     * Sequence name is hard-coded by Doctrine convention (<table>_<column>_seq):
     * pg_get_serial_sequence() returns NULL here because Doctrine creates the
     * sequence as a standalone object (no DEFAULT nextval(), no pg_depend link)
     * and calls NEXTVAL from PHP. The existence check below makes a rename safe.
     *
     * No-op on MySQL/SQLite (they auto-advance their own counter).
     */
    private function resyncDoctrineSequence(Connection $conn, string $table, string $sequence, string $column): void
    {
        try {
            // Match by short-name so it works on both DBAL <4 (PostgreSqlPlatform)
            // and DBAL ≥4 (PostgreSQLPlatform).
            $platform = $conn->getDatabasePlatform();
            $platformName = strtolower((new \ReflectionClass($platform))->getShortName());
            if (strpos($platformName, 'postgres') === false) {
                return;
            }

            $exists = (int) $conn->fetchOne(
                "SELECT COUNT(*) FROM pg_class WHERE relkind = 'S' AND relname = ?",
                [$sequence]
            );
            if ($exists === 0) {
                return;
            }

            $maxId = $conn->fetchOne(
                'SELECT MAX(' . $conn->quoteIdentifier($column) . ') FROM ' . $conn->quoteIdentifier($table)
            );
            if ($maxId === null || $maxId === false) {
                return;
            }

            // setval(name, value) marks the sequence as already called, so the
            // next NEXTVAL returns value + 1.
            $conn->executeStatement('SELECT setval(?, ?)', [$sequence, (int) $maxId]);
        } catch (\Exception $e) {
            // Non-fatal: restore already succeeded; admin can re-run repair.
            log_error('KOMOJU: failed to resync sequence ' . $sequence . ': ' . $e->getMessage());
        }
    }
}
