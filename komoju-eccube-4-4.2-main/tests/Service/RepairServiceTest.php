<?php

namespace Tests\Komoju42\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\ORM\EntityManagerInterface;
use Eccube\Common\EccubeConfig;
use Plugin\Komoju42\Service\RepairService;
use PHPUnit\Framework\TestCase;

/**
 * Regression test for the 2026-06-11 SQLSTATE 25P02 system error: probing
 * backup-table existence with `SELECT 1 FROM <table>` aborts the outer PG
 * transaction when the relation is missing, poisoning every later query in
 * the request (including EC-CUBE's own findAllEnabled in regenerateProxy).
 *
 * Pins three invariants so the regression cannot return:
 *   1. hasBackupData() / repair() use SchemaManager metadata, never raw SQL.
 *   2. Fresh install (no backup tables) short-circuits with a zeroed summary.
 *   3. restoreOrderData() filters rows to columns present in the current
 *      schema so column drops/renames between releases don't crash repair.
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
        $this->entityManager->method('getConnection')->willReturn($this->conn);
    }

    public function testRepairOnFreshInstallShortCircuits()
    {
        $this->sm->method('tablesExist')->willReturn(false);

        // Issuing any SELECT/INSERT against a missing table IS the 25P02 bug.
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
     * Backed-up rows from an older schema must be filtered to the current
     * table's columns before INSERT, not crash with "column does not exist".
     */
    public function testRestoreOrderDataFiltersUnknownColumns()
    {
        // Only the orders backup is present; relink + config phases are no-ops.
        $this->sm->method('tablesExist')->willReturnCallback(function ($names) {
            $names = (array)$names;
            return in_array('plg_komoju_order_backup', $names, true);
        });

        // Backup row carries a column the current schema no longer has.
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

        // No duplicate; current schema is a strict subset of the backup.
        // listTableColumns returns Column objects keyed by name; RepairService
        // only uses the keys, so the values can be null in the stub.
        $this->conn->method('fetchOne')->willReturn(0);
        $this->sm->method('listTableColumns')
            ->willReturnCallback(function ($table) {
                if ($table === 'plg_komoju_order') {
                    return [
                        'id'                => null,
                        'order_id'          => null,
                        'komoju_session_id' => null,
                    ];
                }
                return [];
            });

        $captured = null;
        $this->conn->expects($this->once())
            ->method('insert')
            ->willReturnCallback(function ($table, $data) use (&$captured) {
                $captured = ['table' => $table, 'data' => $data];
                return 1;
            });
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
        // id is assigned explicitly (MAX(id)+1) for cross-DB portability.
        $this->assertArrayHasKey('id', $captured['data'], 'id must be set explicitly for cross-DB portability');
        $this->assertSame(1, $captured['data']['id'], 'id should be MAX(id)+1');
        $this->assertSame(1, $summary['orders_restored']);
    }

    // ----- Sequence resync after restore (PostgreSQL only) -----
    // Regression for 2026-06-24: explicit-id INSERTs on PG bypass the
    // sequence Doctrine uses for @GeneratedValue("AUTO"), so the next ORM
    // INSERT calls NEXTVAL and collides on the primary key (SQLSTATE 23505).
    // resyncDoctrineSequence() must run after the restore loop on PG only;
    // MySQL/SQLite auto-advance their own counters from explicit IDs.

    /**
     * Shared arrangement for the resync tests. Returns the captured INSERT
     * and executeStatement calls so each test can assert on them.
     */
    private function arrangeRestoreWithPlatform($platform): array
    {
        $this->sm->method('tablesExist')->willReturnCallback(function ($names) {
            $names = (array)$names;
            return in_array('plg_komoju_order_backup', $names, true);
        });

        $this->conn->method('fetchAllAssociative')
            ->willReturnCallback(function ($sql) {
                if (strpos($sql, 'plg_komoju_order_backup') !== false) {
                    return [['id' => 7, 'order_id' => 42, 'komoju_session_id' => 'sess_abc']];
                }
                return [];
            });

        // fetchOne handles: duplicate check (0 = no dup), MAX(id)+1 / setval
        // MAX(id) (0 = empty table → inserted id becomes 1), and pg_class
        // existence (1 = sequence present).
        $this->conn->method('fetchOne')->willReturnCallback(function ($sql, $params = []) {
            if (strpos($sql, 'COUNT(*) FROM plg_komoju_order WHERE order_id') !== false) return 0;
            if (strpos($sql, 'COUNT(*) FROM pg_class') !== false) return 1;
            if (strpos($sql, 'MAX(') !== false) return 0;
            return null;
        });

        $this->sm->method('listTableColumns')->willReturnCallback(function ($table) {
            if ($table === 'plg_komoju_order') {
                return [
                    'id'                => null,
                    'order_id'          => null,
                    'komoju_session_id' => null,
                ];
            }
            return [];
        });

        $execCalls = [];
        $this->conn->method('executeStatement')->willReturnCallback(function ($sql, $params = []) use (&$execCalls) {
            $execCalls[] = ['sql' => $sql, 'params' => $params];
            return 0;
        });
        $this->conn->method('getDatabasePlatform')->willReturn($platform);
        $this->conn->method('quoteIdentifier')->willReturnCallback(function ($ident) {
            return '"' . str_replace('"', '""', $ident) . '"';
        });

        $captured = [];
        $this->conn->method('insert')
            ->willReturnCallback(function ($table, $data) use (&$captured) {
                $captured[] = ['table' => $table, 'data' => $data];
                return 1;
            });

        return ['exec' => &$execCalls, 'insert' => &$captured];
    }

    /**
     * On PostgreSQL the restore must conclude with
     *   SELECT setval('plg_komoju_order_id_seq', <MAX(id)>)
     * so the next ORM INSERT doesn't collide on the PK.
     */
    public function testRestoreOrderDataResyncsSequenceOnPostgres()
    {
        $captures = $this->arrangeRestoreWithPlatform(new StubPostgresPlatform());

        $service = new RepairService(
            $this->entityManager,
            $this->createMock(EccubeConfig::class)
        );
        $summary = $service->repair();

        $this->assertSame(1, $summary['orders_restored']);

        $setval = null;
        foreach ($captures['exec'] as $call) {
            if (stripos($call['sql'], 'setval') !== false) { $setval = $call; break; }
        }
        $this->assertNotNull($setval, 'expected SELECT setval(...) after restore on PostgreSQL');
        $this->assertSame('SELECT setval(?, ?)', $setval['sql']);
        $this->assertSame('plg_komoju_order_id_seq', $setval['params'][0],
            'sequence name must follow the Doctrine convention <table>_<column>_seq');
        // Exact MAX(id) value depends on the live DB; assert only its shape.
        $this->assertIsInt($setval['params'][1]);
        $this->assertGreaterThanOrEqual(0, $setval['params'][1]);
    }

    /** No-op on MySQL: AUTO_INCREMENT advances on explicit-id INSERTs. */
    public function testRestoreOrderDataSkipsResyncOnMySql()
    {
        $captures = $this->arrangeRestoreWithPlatform(new StubMysqlPlatform());

        $service = new RepairService(
            $this->entityManager,
            $this->createMock(EccubeConfig::class)
        );
        $service->repair();

        foreach ($captures['exec'] as $call) {
            $this->assertStringNotContainsStringIgnoringCase('setval', $call['sql'],
                'must not call setval on MySQL');
            $this->assertStringNotContainsString('pg_class', $call['sql'],
                'must not touch pg_class on MySQL');
        }
    }

    /** No-op on SQLite (EC-CUBE's default for the dev container). */
    public function testRestoreOrderDataSkipsResyncOnSqlite()
    {
        $captures = $this->arrangeRestoreWithPlatform(new StubSqlitePlatform());

        $service = new RepairService(
            $this->entityManager,
            $this->createMock(EccubeConfig::class)
        );
        $service->repair();

        foreach ($captures['exec'] as $call) {
            $this->assertStringNotContainsStringIgnoringCase('setval', $call['sql'],
                'must not call setval on SQLite');
        }
    }

    /**
     * Defence in depth: if the conventional sequence name is missing
     * (rename, custom strategy, rebuilt schema), resync silently no-ops.
     */
    public function testRestoreOrderDataNoResyncWhenSequenceMissing()
    {
        // Same arrangement as the PG happy path but pg_class probe returns 0.
        $this->sm->method('tablesExist')->willReturnCallback(function ($names) {
            $names = (array)$names;
            return in_array('plg_komoju_order_backup', $names, true);
        });
        $this->conn->method('fetchAllAssociative')
            ->willReturnCallback(function ($sql) {
                if (strpos($sql, 'plg_komoju_order_backup') !== false) {
                    return [['id' => 7, 'order_id' => 42, 'komoju_session_id' => 'sess_abc']];
                }
                return [];
            });
        $this->conn->method('fetchOne')->willReturnCallback(function ($sql, $params = []) {
            if (strpos($sql, 'COUNT(*) FROM plg_komoju_order WHERE order_id') !== false) return 0;
            if (strpos($sql, 'COUNT(*) FROM pg_class') !== false) return 0; // missing
            if (strpos($sql, 'MAX(') !== false) return 0;
            return null;
        });
        $this->sm->method('listTableColumns')->willReturnCallback(function ($table) {
            return $table === 'plg_komoju_order'
                ? ['id' => null, 'order_id' => null, 'komoju_session_id' => null]
                : [];
        });
        $this->conn->method('getDatabasePlatform')->willReturn(new StubPostgresPlatform());
        $this->conn->method('quoteIdentifier')->willReturnCallback(function ($i) {
            return '"' . $i . '"';
        });

        $execCalls = [];
        $this->conn->method('executeStatement')->willReturnCallback(function ($sql, $params = []) use (&$execCalls) {
            $execCalls[] = $sql;
            return 0;
        });
        $this->conn->method('insert')->willReturn(1);

        $service = new RepairService(
            $this->entityManager,
            $this->createMock(EccubeConfig::class)
        );
        $service->repair(); // must not throw

        foreach ($execCalls as $sql) {
            $this->assertStringNotContainsStringIgnoringCase('setval', $sql,
                'no setval when the sequence is missing');
        }
    }

    /** If every backup row was a duplicate, no rows inserted → no resync. */
    public function testRestoreOrderDataSkipsResyncWhenNothingInserted()
    {
        $this->sm->method('tablesExist')->willReturnCallback(function ($names) {
            $names = (array)$names;
            return in_array('plg_komoju_order_backup', $names, true);
        });
        $this->conn->method('fetchAllAssociative')
            ->willReturnCallback(function ($sql) {
                if (strpos($sql, 'plg_komoju_order_backup') !== false) {
                    return [['id' => 1, 'order_id' => 42]];
                }
                return [];
            });
        // Duplicate check returns >0 → row skipped, nothing inserted.
        $this->conn->method('fetchOne')->willReturnCallback(function ($sql, $params = []) {
            if (strpos($sql, 'COUNT(*) FROM plg_komoju_order WHERE order_id') !== false) return 1;
            if (strpos($sql, 'MAX(') !== false) return 0;
            return null;
        });
        $this->sm->method('listTableColumns')->willReturnCallback(function ($table) {
            return $table === 'plg_komoju_order'
                ? ['id' => null, 'order_id' => null]
                : [];
        });
        $this->conn->method('getDatabasePlatform')->willReturn(new StubPostgresPlatform());

        $execCalls = [];
        $this->conn->method('executeStatement')->willReturnCallback(function ($sql) use (&$execCalls) {
            $execCalls[] = $sql;
            return 0;
        });
        $this->conn->expects($this->never())->method('insert');

        $service = new RepairService(
            $this->entityManager,
            $this->createMock(EccubeConfig::class)
        );
        $service->repair();

        foreach ($execCalls as $sql) {
            $this->assertStringNotContainsStringIgnoringCase('setval', $sql,
                'no rows inserted → no sequence resync');
        }
    }

    // ----- relinkOrderPayments — mutates dtb_order, deletes dtb_payment -----
    // Orders are repointed when a matching new Payment exists for the slug.
    // Orphan Payments are deleted only when no Order still references them.

    /** Arrange a repair() that only exercises the relink phase. */
    private function arrangeRelinkOnly(array $oldMappings, array $newMappings, array $orphanOrderCounts = []): array
    {
        $this->sm->method('tablesExist')->willReturnCallback(function ($names) {
            $names = (array)$names;
            return in_array('plg_komoju_payments_backup', $names, true);
        });

        $this->conn->method('fetchAllAssociative')->willReturnCallback(function ($sql) use ($oldMappings, $newMappings) {
            if (strpos($sql, 'plg_komoju_payments_backup') !== false) {
                return $oldMappings;
            }
            if (strpos($sql, 'plg_komoju_payments') !== false) {
                return $newMappings;
            }
            return [];
        });

        // Orphan-Payment safety check: SELECT COUNT(*) FROM dtb_order WHERE payment_id = ?
        $this->conn->method('fetchOne')->willReturnCallback(function ($sql, $params = []) use ($orphanOrderCounts) {
            if (strpos($sql, 'dtb_order WHERE payment_id') !== false) {
                $pid = $params[0] ?? null;
                return $orphanOrderCounts[$pid] ?? 0;
            }
            return null;
        });

        $execCalls = [];
        $this->conn->method('executeStatement')->willReturnCallback(function ($sql, $params = []) use (&$execCalls) {
            $execCalls[] = ['sql' => $sql, 'params' => $params];
            return 1;
        });

        return ['exec' => &$execCalls];
    }

    public function testRelinkRewritesOrderPaymentIds()
    {
        // paypay: Payment#50 → Payment#75.
        $captures = $this->arrangeRelinkOnly(
            [['payment_id' => 50, 'name' => 'paypay']],
            [['payment_id' => 75, 'name' => 'paypay']]
        );

        $service = new RepairService(
            $this->entityManager,
            $this->createMock(EccubeConfig::class)
        );
        $summary = $service->repair();

        $this->assertSame(1, $summary['orders_relinked']);

        $update = null;
        foreach ($captures['exec'] as $call) {
            if (stripos($call['sql'], 'UPDATE dtb_order') !== false) { $update = $call; break; }
        }
        $this->assertNotNull($update, 'expected UPDATE dtb_order');
        $this->assertSame([75, 50], $update['params']);
    }

    public function testRelinkSkipsWhenNewPaymentForSlugIsMissing()
    {
        // "linepay" existed in the old plugin but not the new install.
        $captures = $this->arrangeRelinkOnly(
            [['payment_id' => 50, 'name' => 'linepay']],
            [['payment_id' => 75, 'name' => 'paypay']]
        );

        $service = new RepairService(
            $this->entityManager,
            $this->createMock(EccubeConfig::class)
        );
        $summary = $service->repair();

        $this->assertSame(0, $summary['orders_relinked'],
            'no relink should occur when the slug has no replacement');

        foreach ($captures['exec'] as $call) {
            $this->assertStringNotContainsString('UPDATE dtb_order', $call['sql'] ?? '',
                'must not issue UPDATE dtb_order when no replacement exists');
        }
    }

    public function testRelinkSkipsWhenOldAndNewIdsMatch()
    {
        // Same id on both sides → nothing to rewrite.
        $captures = $this->arrangeRelinkOnly(
            [['payment_id' => 50, 'name' => 'paypay']],
            [['payment_id' => 50, 'name' => 'paypay']]
        );

        $service = new RepairService(
            $this->entityManager,
            $this->createMock(EccubeConfig::class)
        );
        $summary = $service->repair();

        $this->assertSame(0, $summary['orders_relinked']);
        foreach ($captures['exec'] as $call) {
            $this->assertStringNotContainsString('UPDATE dtb_order', $call['sql'] ?? '');
        }
    }

    public function testRelinkDeletesOrphanPaymentWithNoOrders()
    {
        // Old Payment#99 has no replacement and no orders → must be deleted.
        $captures = $this->arrangeRelinkOnly(
            [['payment_id' => 99, 'name' => 'old_method']],
            [['payment_id' => 75, 'name' => 'paypay']],
            [99 => 0]
        );

        $service = new RepairService(
            $this->entityManager,
            $this->createMock(EccubeConfig::class)
        );
        $summary = $service->repair();

        $this->assertSame(1, $summary['orphans_deleted']);

        $deletes = array_filter($captures['exec'], function ($call) {
            return stripos($call['sql'], 'DELETE FROM') !== false;
        });
        $deletes = array_values($deletes);
        $this->assertCount(2, $deletes, 'orphan deletion issues two deletes (options, then payment)');
        $this->assertStringContainsString('dtb_payment_option', $deletes[0]['sql']);
        $this->assertStringContainsString('dtb_payment', $deletes[1]['sql']);
        $this->assertSame([99], $deletes[0]['params']);
        $this->assertSame([99], $deletes[1]['params']);
    }

    public function testRelinkPreservesOrphanPaymentStillReferencedByOrders()
    {
        // Old Payment#99 has no replacement but 3 orders reference it —
        // deleting it would cascade and break historical orders.
        $captures = $this->arrangeRelinkOnly(
            [['payment_id' => 99, 'name' => 'old_method']],
            [['payment_id' => 75, 'name' => 'paypay']],
            [99 => 3]
        );

        $service = new RepairService(
            $this->entityManager,
            $this->createMock(EccubeConfig::class)
        );
        $summary = $service->repair();

        $this->assertSame(0, $summary['orphans_deleted'],
            'must not delete a Payment that orders still reference');
        foreach ($captures['exec'] as $call) {
            $this->assertStringNotContainsString('DELETE FROM dtb_payment', $call['sql'] ?? '',
                'orphan delete must not be issued when orders still reference the payment');
        }
    }
}

// Platform stubs for the resync tests. resyncDoctrineSequence() detects the
// platform via the class short-name (case-insensitive "postgres" substring),
// so only the names of these empty classes matter.
class StubPostgresPlatform {}
class StubMysqlPlatform {}
class StubSqlitePlatform {}
