<?php

namespace Tests\Komoju42\Service;

use Plugin\Komoju42\Entity\KomojuConfig;
use Plugin\Komoju42\Service\LogService;
use PHPUnit\Framework\TestCase;

class LogServiceTest extends TestCase
{
    private $entityManager;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);
    }

    private function makeConfigRepo(?KomojuConfig $config = null)
    {
        $repo = $this->createMock(StubRepository::class);
        $repo->method('findOneBy')->willReturn($config);
        return $repo;
    }

    public function testWriteLogWhenEnabled()
    {
        $config = new KomojuConfig();
        $config->setLoggingEnabled(true);

        $this->entityManager->method('getRepository')->willReturn($this->makeConfigRepo($config));
        $this->entityManager->expects($this->once())->method('persist')
            ->with($this->callback(function ($log) {
                return $log->getApi() === 'test_api'
                    && $log->getOrderId() === '42'
                    && $log->getMsg() === 'hello';
            }));
        $this->entityManager->expects($this->once())->method('flush');

        $service = new LogService($this->entityManager);
        $service->writeLog('test_api', '42', 'hello');
    }

    public function testWriteLogWhenDisabled()
    {
        $config = new KomojuConfig();
        $config->setLoggingEnabled(false);

        $this->entityManager->method('getRepository')->willReturn($this->makeConfigRepo($config));
        $this->entityManager->expects($this->never())->method('persist');

        $service = new LogService($this->entityManager);
        $service->writeLog('test_api', '42', 'hello');
    }

    public function testProtectedBypassesDisabled()
    {
        $config = new KomojuConfig();
        $config->setLoggingEnabled(false);

        $this->entityManager->method('getRepository')->willReturn($this->makeConfigRepo($config));
        $this->entityManager->expects($this->once())->method('persist');
        $this->entityManager->expects($this->once())->method('flush');

        $service = new LogService($this->entityManager);
        $service->writeLog('test_api', '42', 'important', true);
    }

    public function testConfigCached()
    {
        $config = new KomojuConfig();
        $config->setLoggingEnabled(true);

        $repo = $this->makeConfigRepo($config);
        $repo->expects($this->once())->method('findOneBy');

        $this->entityManager->method('getRepository')->willReturn($repo);
        $this->entityManager->method('persist');
        $this->entityManager->method('flush');

        $service = new LogService($this->entityManager);
        $service->writeLog('api1', '1', 'msg1');
        $service->writeLog('api2', '2', 'msg2');
    }
}

/**
 * @internal Minimal stub so we can mock findOneBy
 */
class StubRepository
{
    public function findOneBy(array $criteria) { return null; }
    public function find($id) { return null; }
    public function findBy(array $criteria, ?array $orderBy = null) { return []; }
    public function updateOrderSummary($customer) {}
}
