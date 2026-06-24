<?php

namespace Tests\Komoju42\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\ORM\EntityManagerInterface;
use Eccube\Common\EccubeConfig;
use Plugin\Komoju42\Service\RepairService;
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

    // ------------------------------------------------------------------
    // Sequence resync after restore (PostgreSQL only)
    //
    // Regression: on PostgreSQL, restoreOrderData() inserts rows with
    // explicit IDs via raw DBAL. That bypasses the sequence backing
    // @GeneratedValue(strategy="AUTO") on plg_komoju_order.id, leaving
    // last_value frozen at its initial position. The very next ORM-driven
    // INSERT then calls NEXTVAL, gets a value already present in the table,
    // and crashes the checkout with SQLSTATE 23505 — the exact production
    // bug observed on 2026-06-24. resyncDoctrineSequence() must be called
    // after the restore loop, but ONLY on PostgreSQL (MySQL and SQLite
    // advance their own counters from explicit INSERTs).
    // ------------------------------------------------------------------

    /**
     * Build the standard "backup exists, restore writes one row" arrangement
     * shared by the sequence-resync tests. Returns the captured INSERT and
     * executeStatement calls so tests can assert on them.
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

        // fetchOne is called for: order_id duplicate check, MAX(id)+1, pg_class
        // existence, and MAX(id) for setval. We make it stateful by argument:
        //   - any SELECT COUNT/MAX/pg_class returns the appropriate value.
        $this->conn->method('fetchOne')->willReturnCallback(function ($sql, $params = []) {
            if (strpos($sql, 'COUNT(*) FROM plg_komoju_order WHERE order_id') !== false) {
                return 0; // no duplicate
            }
            if (strpos($sql, 'COUNT(*) FROM pg_class') !== false) {
                return 1; // sequence exists
            }
            if (strpos($sql, 'MAX(') !== false) {
                // Used both for the initial MAX(id)+1 (before insert) and for
                // the setval MAX(...). Both can safely return the same value:
                // before insert the table is empty (MAX = 0), but the second
                // call (after the loop) should reflect the inserted row. We
                // return 0 here so the inserted row's explicit id becomes 1.
                // The setval reads MAX again — we'll override in tests that
                // need to assert the setval argument exactly.
                return 0;
            }
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

        // Capture all executeStatement calls so the test can inspect what was issued.
        $execCalls = [];
        $this->conn->method('executeStatement')->willReturnCallback(function ($sql, $params = []) use (&$execCalls) {
            $execCalls[] = ['sql' => $sql, 'params' => $params];
            return 0;
        });

        // The platform stub drives the postgres detection branch.
        $this->conn->method('getDatabasePlatform')->willReturn($platform);

        // quoteIdentifier is platform-agnostic in our use; double-quote suffices.
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
     * On PostgreSQL, the restore must conclude with
     *   SELECT setval('plg_komoju_order_id_seq', <MAX(id)>)
     * so that the next ORM-driven INSERT doesn't collide on the primary key.
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

        // Locate the setval call among the executeStatement invocations.
        $setval = null;
        foreach ($captures['exec'] as $call) {
            if (stripos($call['sql'], 'setval') !== false) {
                $setval = $call;
                break;
            }
        }
        $this->assertNotNull($setval, 'expected SELECT setval(...) after restore on PostgreSQL');
        $this->assertSame('SELECT setval(?, ?)', $setval['sql']);
        $this->assertSame('plg_komoju_order_id_seq', $setval['params'][0],
            'sequence name must be the Doctrine convention <table>_<column>_seq');
        // The inserted row got id=1 (MAX(id)+1 with empty table). setval value
        // reflects MAX(id) AFTER insert; our mock returns 0 for MAX (see
        // helper). The important assertion is that setval was issued at all
        // with the right sequence name — the exact value is determined by the
        // live DB. Still, assert it is an int >= 0.
        $this->assertIsInt($setval['params'][1]);
        $this->assertGreaterThanOrEqual(0, $setval['params'][1]);
    }

    /**
     * On MySQL the resync must be a NO-OP. MySQL advances AUTO_INCREMENT
     * when an explicit id is inserted, so any setval-equivalent call would
     * either error or be meaningless.
     */
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

    /**
     * Same as the MySQL case, for SQLite. Important on local dev machines
     * (EC-CUBE defaults to SQLite for the dev container).
     */
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
     * Defence in depth: if the Doctrine-conventional sequence name doesn't
     * exist (Doctrine renamed it, schema rebuilt, custom strategy), the resync
     * must silently no-op rather than crashing the repair flow.
     */
    public function testRestoreOrderDataNoResyncWhenSequenceMissing()
    {
        // Configure base arrangement EXCEPT have the pg_class probe return 0.
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
            if (strpos($sql, 'COUNT(*) FROM pg_class') !== false) return 0; // sequence MISSING
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
                'setval must not be issued when the sequence is missing');
        }
    }

    /**
     * If no rows were inserted (every backup row was a duplicate), the resync
     * must not run — there's nothing to fix and a stray setval would be noise.
     */
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
        // Duplicate check returns >0 so the row is skipped.
        $this->conn->method('fetchOne')->willReturnCallback(function ($sql, $params = []) {
            if (strpos($sql, 'COUNT(*) FROM plg_komoju_order WHERE order_id') !== false) {
                return 1; // already present
            }
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

        // insert should never be called either.
        $this->conn->expects($this->never())->method('insert');

        $service = new RepairService(
            $this->entityManager,
            $this->createMock(EccubeConfig::class)
        );
        $service->repair();

        foreach ($execCalls as $sql) {
            $this->assertStringNotContainsStringIgnoringCase('setval', $sql,
                'no rows inserted means no sequence resync should fire');
        }
    }

    // ------------------------------------------------------------------
    // relinkOrderPayments — mutates dtb_order and deletes dtb_payment.
    // These tests pin the contract: orders are repointed when there's a
    // matching new Payment for a stable slug, orphan Payments are deleted
    // only when no Order still references them, and the repair never
    // touches anything when the payments-backup table is absent.
    // ------------------------------------------------------------------

    /**
     * Helper to arrange a repair() invocation that exercises only the
     * relink phase (order-restore and config-restore are no-ops).
     */
    private function arrangeRelinkOnly(array $oldMappings, array $newMappings, array $orphanOrderCounts = []): array
    {
        // Only the payments-backup table is present.
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

        $this->conn->method('fetchOne')->willReturnCallback(function ($sql, $params = []) use ($orphanOrderCounts) {
            // Order-count lookup for orphan payments: SELECT COUNT(*) FROM dtb_order WHERE payment_id = ?
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
        // Old: paypay was Payment#50. New: paypay is Payment#75.
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

        // The UPDATE dtb_order SET payment_id = 75 WHERE payment_id = 50 was issued.
        $update = null;
        foreach ($captures['exec'] as $call) {
            if (stripos($call['sql'], 'UPDATE dtb_order') !== false) {
                $update = $call;
                break;
            }
        }
        $this->assertNotNull($update, 'expected UPDATE dtb_order');
        $this->assertSame([75, 50], $update['params']);
    }

    public function testRelinkSkipsWhenNewPaymentForSlugIsMissing()
    {
        // Old plugin offered "linepay" but the new install doesn't have it.
        // Nothing in dtb_order should be repointed for it.
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
        // If the new Payment kept the same id, there's nothing to rewrite.
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
        // Old payment id 99 has no matching new payment AND no orders reference
        // it after the relink phase. It should be deleted from dtb_payment.
        $captures = $this->arrangeRelinkOnly(
            [['payment_id' => 99, 'name' => 'old_method']],
            [['payment_id' => 75, 'name' => 'paypay']],
            [99 => 0] // no orders reference 99
        );

        $service = new RepairService(
            $this->entityManager,
            $this->createMock(EccubeConfig::class)
        );
        $summary = $service->repair();

        $this->assertSame(1, $summary['orphans_deleted']);

        // Both DELETE statements were issued for payment_id = 99.
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
        // Old payment id 99 has no replacement but 3 orders still reference it.
        // Deleting it would cascade and break historical orders, so the method
        // MUST NOT issue a DELETE for payment 99.
        $captures = $this->arrangeRelinkOnly(
            [['payment_id' => 99, 'name' => 'old_method']],
            [['payment_id' => 75, 'name' => 'paypay']],
            [99 => 3] // 3 orders still using payment 99
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

// ----------------------------------------------------------------------
// Platform stubs for the resync tests.
//
// resyncDoctrineSequence() detects the platform by reading the database
// platform's class short-name and looking for the substring "postgres". We
// can't extend Doctrine's real PostgreSQLPlatform here (not available in the
// test runtime), so these stubs use class names whose short-names contain
// or omit "postgres" as appropriate. The class name is what's significant,
// not the methods.
// ----------------------------------------------------------------------

class StubPostgresPlatform { /* short-name contains "postgres" — case-insensitive match */ }
class StubMysqlPlatform { /* short-name does not contain "postgres" */ }
class StubSqlitePlatform { /* short-name does not contain "postgres" */ }
