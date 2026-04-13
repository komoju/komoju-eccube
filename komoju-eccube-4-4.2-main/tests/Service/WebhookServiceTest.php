<?php

namespace Tests\Komoju42\Service;

use Plugin\Komoju42\Entity\KomojuOrder;
use Plugin\Komoju42\Service\LogService;
use Plugin\Komoju42\Service\WebhookService;
use Eccube\Entity\Master\OrderStatus;
use Eccube\Entity\Order;
use Eccube\Service\OrderStateMachine;
use PHPUnit\Framework\TestCase;

class WebhookServiceTest extends TestCase
{
    private $entityManager;
    private $orderStateMachine;
    private $logService;
    private $komojuOrderRepo;
    private $service;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);
        $this->orderStateMachine = $this->createMock(OrderStateMachine::class);
        $this->logService = $this->createMock(LogService::class);
        $this->komojuOrderRepo = $this->createMock(StubRepository::class);

        $productStockRepo = $this->createMock(StubRepository::class);

        $this->entityManager->method('getRepository')
            ->willReturnCallback(function ($class) use ($productStockRepo) {
                if ($class === KomojuOrder::class) {
                    return $this->komojuOrderRepo;
                }
                return $productStockRepo;
            });

        $this->service = new WebhookService(
            $this->entityManager,
            $this->orderStateMachine,
            $this->logService
        );
    }

    private function makeWebhookObject($paymentId, $extras = [])
    {
        $data = (object) array_merge(['id' => $paymentId], $extras);
        return (object) ['data' => $data];
    }

    // --- paymentCaptured ---

    public function testCapturedNoOrder()
    {
        $this->komojuOrderRepo->method('findOneBy')->willReturn(null);
        $this->logService->expects($this->once())->method('writeLog');
        $this->entityManager->expects($this->never())->method('persist');

        $this->service->paymentCaptured($this->makeWebhookObject('pay_1'));
    }

    public function testCapturedAlreadyCaptured()
    {
        $komojuOrder = new KomojuOrder();
        $komojuOrder->setCapturedAt(new \DateTime());

        $this->komojuOrderRepo->method('findOneBy')->willReturn($komojuOrder);
        $this->entityManager->expects($this->never())->method('persist');

        $this->service->paymentCaptured($this->makeWebhookObject('pay_1'));
    }

    public function testCapturedSuccess()
    {
        $orderStatus = new OrderStatus();
        $orderStatus->setId(OrderStatus::PROCESSING);

        $eccubeOrder = new Order();
        $eccubeOrder->setId(99);
        $eccubeOrder->setOrderStatus($orderStatus);

        $komojuOrder = new KomojuOrder();
        $komojuOrder->setOrder($eccubeOrder);

        $this->komojuOrderRepo->method('findOneBy')->willReturn($komojuOrder);

        $paidStatus = new OrderStatus();
        $paidStatus->setId(OrderStatus::PAID);

        $statusRepo = $this->createMock(StubRepository::class);
        $statusRepo->method('find')->willReturn($paidStatus);

        $this->entityManager->method('getRepository')
            ->willReturnCallback(function ($class) use ($statusRepo) {
                if ($class === OrderStatus::class) {
                    return $statusRepo;
                }
                if ($class === KomojuOrder::class) {
                    return $this->komojuOrderRepo;
                }
                return $this->createMock(StubRepository::class);
            });

        $this->service = new WebhookService(
            $this->entityManager,
            $this->orderStateMachine,
            $this->logService
        );

        $object = $this->makeWebhookObject('pay_1', ['captured_at' => '2024-01-15T10:00:00Z']);

        $this->service->paymentCaptured($object);

        $this->assertTrue($komojuOrder->isCaptured());
        $this->assertNotNull($eccubeOrder->getPaymentDate());
    }

    public function testCapturedNoEccubeOrder()
    {
        $komojuOrder = new KomojuOrder();

        $this->komojuOrderRepo->method('findOneBy')->willReturn($komojuOrder);
        $this->logService->expects($this->once())->method('writeLog');

        $object = $this->makeWebhookObject('pay_1', ['captured_at' => '2024-01-15T10:00:00Z']);
        $this->service->paymentCaptured($object);

        $this->assertTrue($komojuOrder->isCaptured());
    }

    // --- paymentRefunded ---

    public function testRefundedEmptyRefunds()
    {
        $object = $this->makeWebhookObject('pay_1', ['refunds' => []]);

        $this->logService->expects($this->once())->method('writeLog');
        $this->entityManager->expects($this->never())->method('persist');

        $this->service->paymentRefunded($object);
    }

    public function testRefundedNoOrder()
    {
        $object = $this->makeWebhookObject('pay_1', ['refunds' => [(object)['id' => 'ref_1', 'amount' => 100]]]);

        $this->komojuOrderRepo->method('findOneBy')->willReturn(null);
        $this->logService->expects($this->once())->method('writeLog');

        $this->service->paymentRefunded($object);
    }

    public function testRefundedSuccess()
    {
        $orderStatus = new OrderStatus();
        $orderStatus->setId(OrderStatus::PAID);

        $eccubeOrder = new Order();
        $eccubeOrder->setId(55);
        $eccubeOrder->setOrderStatus($orderStatus);

        $komojuOrder = new KomojuOrder();
        $komojuOrder->setOrder($eccubeOrder);

        $this->komojuOrderRepo->method('findOneBy')->willReturn($komojuOrder);

        $cancelStatus = new OrderStatus();
        $cancelStatus->setId(OrderStatus::CANCEL);

        $statusRepo = $this->createMock(StubRepository::class);
        $statusRepo->method('find')->willReturn($cancelStatus);

        $this->entityManager->method('getRepository')
            ->willReturnCallback(function ($class) use ($statusRepo) {
                if ($class === OrderStatus::class) return $statusRepo;
                if ($class === KomojuOrder::class) return $this->komojuOrderRepo;
                return $this->createMock(StubRepository::class);
            });

        $this->orderStateMachine->method('can')->willReturn(true);

        $this->service = new WebhookService(
            $this->entityManager,
            $this->orderStateMachine,
            $this->logService
        );

        $object = $this->makeWebhookObject('pay_1', [
            'refunds' => [(object)['id' => 'ref_1', 'amount' => 500]]
        ]);

        $this->service->paymentRefunded($object);

        $this->assertEquals('ref_1', $komojuOrder->getRefundId());
        $this->assertEquals(500, $komojuOrder->getRefundedAmount());
    }

    public function testRefundedMultipleRefunds()
    {
        $orderStatus = new OrderStatus();
        $orderStatus->setId(OrderStatus::PAID);

        $eccubeOrder = new Order();
        $eccubeOrder->setId(56);
        $eccubeOrder->setOrderStatus($orderStatus);

        $komojuOrder = new KomojuOrder();
        $komojuOrder->setOrder($eccubeOrder);

        $this->komojuOrderRepo->method('findOneBy')->willReturn($komojuOrder);

        $cancelStatus = new OrderStatus();
        $cancelStatus->setId(OrderStatus::CANCEL);

        $statusRepo = $this->createMock(StubRepository::class);
        $statusRepo->method('find')->willReturn($cancelStatus);

        $this->entityManager->method('getRepository')
            ->willReturnCallback(function ($class) use ($statusRepo) {
                if ($class === OrderStatus::class) return $statusRepo;
                if ($class === KomojuOrder::class) return $this->komojuOrderRepo;
                return $this->createMock(StubRepository::class);
            });

        $this->orderStateMachine->method('can')->willReturn(true);

        $this->service = new WebhookService(
            $this->entityManager,
            $this->orderStateMachine,
            $this->logService
        );

        $object = $this->makeWebhookObject('pay_1', [
            'refunds' => [
                (object)['id' => 'ref_1', 'amount' => 300],
                (object)['id' => 'ref_2', 'amount' => 200],
            ]
        ]);

        $this->service->paymentRefunded($object);

        $this->assertEquals('ref_1,ref_2', $komojuOrder->getRefundId());
        $this->assertEquals(500, $komojuOrder->getRefundedAmount());
    }

    // --- cancel events ---

    public function testCancelNoOrder()
    {
        $this->komojuOrderRepo->method('findOneBy')->willReturn(null);
        $this->logService->expects($this->once())->method('writeLog');

        $this->service->paymentCanceled($this->makeWebhookObject('pay_1'));
    }

    public function testCancelAlreadyCanceled()
    {
        $orderStatus = new OrderStatus();
        $orderStatus->setId(OrderStatus::PROCESSING);

        $eccubeOrder = new Order();
        $eccubeOrder->setId(77);
        $eccubeOrder->setOrderStatus($orderStatus);
        $eccubeOrder->setOrderItems([]);

        $komojuOrder = new KomojuOrder();
        $komojuOrder->setOrder($eccubeOrder);
        $komojuOrder->setCanceledAt(new \DateTime());

        $this->komojuOrderRepo->method('findOneBy')->willReturn($komojuOrder);

        $this->orderStateMachine->expects($this->never())->method('apply');

        $this->service->paymentCanceled($this->makeWebhookObject('pay_1'));
    }

    public function testCancelSuccess()
    {
        $orderStatus = new OrderStatus();
        $orderStatus->setId(OrderStatus::PROCESSING);

        $eccubeOrder = new Order();
        $eccubeOrder->setId(78);
        $eccubeOrder->setOrderStatus($orderStatus);
        $eccubeOrder->setOrderItems([]);

        $komojuOrder = new KomojuOrder();
        $komojuOrder->setOrder($eccubeOrder);

        $this->komojuOrderRepo->method('findOneBy')->willReturn($komojuOrder);

        $cancelStatus = new OrderStatus();
        $cancelStatus->setId(OrderStatus::CANCEL);

        $this->entityManager->method('find')->willReturn($cancelStatus);
        $this->orderStateMachine->method('can')->willReturn(true);
        $this->orderStateMachine->expects($this->once())->method('apply');

        $this->service->paymentCanceled($this->makeWebhookObject('pay_1'));

        $this->assertNotNull($komojuOrder->getCanceledAt());
    }

    // --- paymentUpdated ---

    public function testUpdatedExpired()
    {
        $orderStatus = new OrderStatus();
        $orderStatus->setId(OrderStatus::PROCESSING);

        $eccubeOrder = new Order();
        $eccubeOrder->setId(80);
        $eccubeOrder->setOrderStatus($orderStatus);
        $eccubeOrder->setOrderItems([]);

        $komojuOrder = new KomojuOrder();
        $komojuOrder->setOrder($eccubeOrder);

        $this->komojuOrderRepo->method('findOneBy')->willReturn($komojuOrder);

        $cancelStatus = new OrderStatus();
        $cancelStatus->setId(OrderStatus::CANCEL);
        $this->entityManager->method('find')->willReturn($cancelStatus);
        $this->orderStateMachine->method('can')->willReturn(true);

        $object = $this->makeWebhookObject('pay_1', ['status' => 'expired']);
        $this->service->paymentUpdated($object);

        $this->assertNotNull($komojuOrder->getCanceledAt());
    }

    public function testUpdatedCancelled()
    {
        $orderStatus = new OrderStatus();
        $orderStatus->setId(OrderStatus::PROCESSING);

        $eccubeOrder = new Order();
        $eccubeOrder->setId(81);
        $eccubeOrder->setOrderStatus($orderStatus);
        $eccubeOrder->setOrderItems([]);

        $komojuOrder = new KomojuOrder();
        $komojuOrder->setOrder($eccubeOrder);

        $this->komojuOrderRepo->method('findOneBy')->willReturn($komojuOrder);

        $cancelStatus = new OrderStatus();
        $cancelStatus->setId(OrderStatus::CANCEL);
        $this->entityManager->method('find')->willReturn($cancelStatus);
        $this->orderStateMachine->method('can')->willReturn(true);

        $object = $this->makeWebhookObject('pay_1', ['status' => 'cancelled']);
        $this->service->paymentUpdated($object);

        $this->assertNotNull($komojuOrder->getCanceledAt());
    }

    public function testUpdatedOtherStatus()
    {
        $komojuOrder = new KomojuOrder();

        $this->komojuOrderRepo->method('findOneBy')->willReturn($komojuOrder);
        $this->orderStateMachine->expects($this->never())->method('apply');

        $object = $this->makeWebhookObject('pay_1', ['status' => 'authorized']);
        $this->service->paymentUpdated($object);

        $this->assertNull($komojuOrder->getCanceledAt());
    }
}
