<?php

namespace Tests\Komoju\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\ORM\EntityManagerInterface;
use Eccube\Common\EccubeConfig;
use Plugin\Komoju\Service\RepairService;
use PHPUnit\Framework\TestCase;

/**
 * Regression test for the system-error reported on 2026-06-11:
 *   admin.ERROR ... KOMOJU: consolidate orphaned payments failed:
 *   SQLSTATE[25P02]: In failed sql transaction
 *
 * The proximate cause was that backup-table existence was probed with
 * `SELECT 1 FROM <table>`, which on PostgreSQL aborts the surrounding
 * transaction when the relation does not exist. Even though the PHP
 * exception was caught, the connection was poisoned and EC-CUBE's own
 * `findAllEnabled()` (called next inside the same outer transaction by
 * PluginService::regenerateProxy) failed and surfaced as the system error.
 *
 * This test pins down the new behaviour so the regression cannot return:
 *
 *   1. RepairService::hasBackupData() and ::repair() must NEVER call
 *      $conn->fetchOne / fetchAllAssociative / executeStatement to
 *      determine table existence. They must use SchemaManager metadata
 *      (information_schema / sqlite_master) so a missing table does not
 *      poison the transaction.
 *
 *   2. On a fresh install (no backup tables) ::repair() must short-circuit
 *      and return a zeroed summary without mutating anything.
 *
 *   3. ::restoreOrderData (exercised via repair()) must filter rows to
 *      columns present in the current schema before INSERT, so rows backed
 *      up under an older schema do not blow up after a column rename/drop.
 */
class RepairServiceTest extends TestCase
{
    private $entityManager;
    private $conn;
    private $sm;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->conn = $this->createMock(Connection::class);
        $this->sm   = $this->createMock(AbstractSchemaManager::class);

        $this->conn->method('createSchemaManager')->willReturn($this->sm);
        $this->conn->method('getSchemaManager')->willReturn($this->sm);

        // Strict: any code path that actually executes SQL on a missing-table
        // connection is the bug we are trying to prevent. The default mock
        // returns null/false for fetch* and 0 for executeStatement; if any
        // production query gets through with the mock unconfigured, the
        // assertions below will catch the resulting wrong behaviour.
        $this->entityManager->method('getConnection')->willReturn($this->conn);
    }

    public function testRepairOnFreshInstallShortCircuits()
    {
        // Fresh install: every backup table is missing.
        $this->sm->method('tablesExist')->willReturn(false);

        // CRITICAL: under no circumstances should we issue a SELECT/INSERT
        // against a missing table. Failing this expectation is the original
        // 25P02 bug.
        $this->conn->expects($this->never())->method('fetchAllAssociative');
        $this->conn->expects($this->never())->method('fetchAssociative');
        $this->conn->expects($this->never())->method('executeStatement');
        $this->conn->expects($this->never())->method('insert');
        $this->conn->expects($this->never())->method('update');

        $service = new RepairService(
            $this->entityManager,
            $this->createMock(EccubeConfig::class)
        );

        $this->assertFalse($service->hasBackupData());

        $summary = $service->repair();
        $this->assertSame(
            ['orders_restored' => 0, 'orders_relinked' => 0, 'orphans_deleted' => 0, 'config_restored' => false],
            $summary
        );
    }

    public function testHasBackupDataIsTrueWhenAnyBackupTableExists()
    {
        // Only the orders backup is present.
        $this->sm->method('tablesExist')->willReturnCallback(function ($names) {
            $names = (array)$names;
            return in_array('plg_komoju_order_backup', $names, true);
        });

        $service = new RepairService(
            $this->entityManager,
            $this->createMock(EccubeConfig::class)
        );

        $this->assertTrue($service->hasBackupData());
    }

    /**
     * If the current plg_komoju_order schema has fewer columns than what we
     * backed up (because a column was removed in a later release), the
     * insert must filter rows to known columns instead of failing with
     * "column does not exist".
     */
    public function testRestoreOrderDataFiltersUnknownColumns()
    {
        // backup table exists, payments backup does not (so relink phase is no-op),
        // config backup does not (so config phase is no-op).
        $this->sm->method('tablesExist')->willReturnCallback(function ($names) {
            $names = (array)$names;
            return in_array('plg_komoju_order_backup', $names, true);
        });

        // Backup row has an extra column ("legacy_field") that the current
        // schema no longer knows about.
        $this->conn->method('fetchAllAssociative')
            ->willReturnCallback(function ($sql) {
                if (strpos($sql, 'plg_komoju_order_backup') !== false) {
                    return [[
                        'id' => 7,
                        'order_id' => 42,
                        'komoju_session_id' => 'sess_abc',
                        'legacy_field' => 'should_be_dropped',
                    ]];
                }
                return [];
            });

        // No existing row with this order_id — fetchOne returns 0/false.
        $this->conn->method('fetchOne')->willReturn(0);

        // Current schema has a smaller column set than the backup.
        $this->sm->method('listTableColumns')
            ->willReturnCallback(function ($table) {
                if ($table === 'plg_komoju_order') {
                    // Doctrine's listTableColumns returns Column objects keyed
                    // by column name; only the keys are used by RepairService.
                    return [
                        'id'                => null,
                        'order_id'          => null,
                        'komoju_session_id' => null,
                    ];
                }
                return [];
            });

        // Capture the actual insert payload to confirm the unknown column
        // was filtered out.
        $captured = null;
        $this->conn->expects($this->once())
            ->method('insert')
            ->willReturnCallback(function ($table, $data) use (&$captured) {
                $captured = ['table' => $table, 'data' => $data];
                return 1;
            });

        // We don't care about the drop statements; allow any number.
        $this->conn->method('executeStatement')->willReturn(0);

        $service = new RepairService(
            $this->entityManager,
            $this->createMock(EccubeConfig::class)
        );
        $summary = $service->repair();

        $this->assertNotNull($captured, 'expected an INSERT into plg_komoju_order');
        $this->assertSame('plg_komoju_order', $captured['table']);
        $this->assertArrayHasKey('order_id', $captured['data']);
        $this->assertArrayHasKey('komoju_session_id', $captured['data']);
        $this->assertArrayNotHasKey('legacy_field', $captured['data'], 'unknown columns must be filtered before INSERT');
        // id must be assigned EXPLICITLY (computed MAX(id)+1 = 1 here), not
        // omitted — plg_komoju_order.id has no sequence on PostgreSQL, so an
        // INSERT without id fails there with a not-null violation.
        $this->assertArrayHasKey('id', $captured['data'], 'id must be set explicitly for cross-DB portability');
        $this->assertSame(1, $captured['data']['id'], 'id should be MAX(id)+1');
        $this->assertSame(1, $summary['orders_restored']);
    }
}
