<?php

namespace Tests\Komoju42\Service;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Plugin\Komoju42\Entity\KomojuConfig;
use Plugin\Komoju42\Service\LogService;

class LogRetentionTest extends TestCase
{
    private $database;
    private $connection;
    private $now;

    protected function setUp(): void
    {
        $this->database = new \PDO('sqlite::memory:');
        $this->database->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->database->exec('CREATE TABLE plg_komoju_log (id INTEGER PRIMARY KEY, created_at TEXT, is_protected INTEGER)');
        $this->connection = new RetentionSqliteConnection($this->database);
        $this->now = new \DateTimeImmutable('2026-11-09 12:00:00', new \DateTimeZone('UTC'));
    }

    public function testPurgePreservesBoundaryAndProtectedHistoryWhenLoggingIsDisabled(): void
    {
        $config = new KomojuConfig();
        $config->setLogRetentionDays(30);
        $config->setLoggingEnabled(false);
        $cutoff = $this->now->modify('-30 days');
        $this->insert(1, $cutoff->modify('-1 second'), 0);
        $this->insert(2, $cutoff->modify('-1 second'), null);
        $this->insert(3, $cutoff->modify('-1 year'), 1);
        $this->insert(4, $cutoff, 0);
        $this->insert(5, $cutoff->modify('+1 second'), 0);

        $service = $this->service($config);
        $this->assertSame(2, $service->purgeExpiredLogs($this->now));
        $this->assertSame([3, 4, 5], $this->ids());
        $this->assertSame(0, $service->purgeExpiredLogs($this->now));
    }

    /** @dataProvider unlimitedRetentionProvider */
    public function testExistingUnlimitedRetentionDoesNotDeleteLogs($days): void
    {
        $config = new KomojuConfig();
        $config->setLogRetentionDays($days);
        $this->insert(1, $this->now->modify('-10 years'), 0);

        $this->assertSame(0, $this->service($config)->purgeExpiredLogs($this->now));
        $this->assertSame([1], $this->ids());
    }

    public function unlimitedRetentionProvider(): array
    {
        return [[null], [0]];
    }

    public function testMissingConfigurationDoesNotDeleteLogs(): void
    {
        $this->insert(1, $this->now->modify('-10 years'), 0);

        $this->assertSame(0, $this->service(null)->purgeExpiredLogs($this->now));
        $this->assertSame([1], $this->ids());
    }

    public function testDatabaseFailureIsNotReportedAsSuccessfulMaintenance(): void
    {
        $this->database->exec('DROP TABLE plg_komoju_log');

        $this->expectException(\PDOException::class);
        $this->service(new KomojuConfig())->purgeExpiredLogs($this->now);
    }

    private function service(?KomojuConfig $config): LogService
    {
        $repository = new class($config) {
            private $config;
            public function __construct($config) { $this->config = $config; }
            public function findOneBy(array $criteria) { return $this->config; }
        };
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);
        $entityManager->method('getConnection')->willReturn($this->connection);

        return new LogService($entityManager);
    }

    private function insert(int $id, \DateTimeImmutable $date, ?int $protected): void
    {
        $statement = $this->database->prepare('INSERT INTO plg_komoju_log VALUES (?, ?, ?)');
        $statement->execute([$id, $date->format('Y-m-d H:i:s'), $protected]);
    }

    private function ids(): array
    {
        return array_map('intval', $this->database->query('SELECT id FROM plg_komoju_log ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN));
    }
}

class RetentionSqliteConnection extends Connection
{
    private $database;

    public function __construct(\PDO $database)
    {
        $this->database = $database;
    }

    public function executeStatement(string $sql, array $params = []): int
    {
        $statement = $this->database->prepare($sql);
        $statement->execute($params);

        return $statement->rowCount();
    }
}
