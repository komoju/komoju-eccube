<?php

namespace Tests\Komoju42\Service;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Plugin\Komoju42\Service\PaymentAttemptTransitionTrait;

class PaymentAttemptTransitionTraitTest extends TestCase
{
    public function testNestedTransactionUsesSavepoint()
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('isTransactionActive')->willReturn(true);
        $connection->expects($this->never())->method('beginTransaction');
        $connection->expects($this->never())->method('commit');
        $connection->expects($this->once())->method('createSavepoint');
        $connection->expects($this->once())->method('releaseSavepoint');

        $host = new PaymentAttemptTransitionHost($this->entityManager($connection));

        $this->assertSame('ok', $host->runTransaction(function () {
            return 'ok';
        }));
    }

    public function testNestedFailureRollsBackOnlySavepoint()
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('isTransactionActive')->willReturn(true);
        $connection->expects($this->never())->method('rollBack');
        $connection->expects($this->once())->method('createSavepoint');
        $connection->expects($this->once())->method('rollbackSavepoint');
        $connection->expects($this->once())->method('releaseSavepoint');

        $entityManager = $this->entityManager($connection);
        $entityManager->expects($this->once())->method('clear');
        $host = new PaymentAttemptTransitionHost($entityManager);
        $this->expectException(\RuntimeException::class);
        $host->runTransaction(function () {
            throw new \RuntimeException('failed');
        });
    }

    private function entityManager(Connection $connection)
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);
        return $entityManager;
    }
}

class PaymentAttemptTransitionHost
{
    use PaymentAttemptTransitionTrait {
        transactional as public runTransaction;
    }

    protected $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }
}
