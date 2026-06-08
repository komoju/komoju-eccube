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

    // --- paymentAuthorized ---

    public function testAuthorizedNoOrder()
    {
        $this->komojuOrderRepo->method('findOneBy')->willReturn(null);
        $this->logService->expects($this->once())->method('writeLog');
        $this->entityManager->expects($this->never())->method('persist');

        $this->service->paymentAuthorized($this->makeWebhookObject('pay_1'));
    }

    public function testAuthorizedPopulatesPaymentId()
    {
        $orderStatus = new OrderStatus();
        $orderStatus->setId(OrderStatus::PENDING);

        $eccubeOrder = new Order();
        $eccubeOrder->setId(90);
        $eccubeOrder->setOrderStatus($orderStatus);

        $komojuOrder = new KomojuOrder();
        $komojuOrder->setOrder($eccubeOrder);

        $this->komojuOrderRepo->method('findOneBy')->willReturn($komojuOrder);

        $newStatus = new OrderStatus();
        $newStatus->setId(OrderStatus::NEW);
        $this->entityManager->method('find')->willReturn($newStatus);

        $object = $this->makeWebhookObject('pay_auth_1', [
            'payment_details' => (object)['type' => 'konbini'],
        ]);

        $this->service->paymentAuthorized($object);

        $this->assertEquals('pay_auth_1', $komojuOrder->getKomojuPaymentId());
        $this->assertEquals('konbini', $komojuOrder->getType());
    }

    public function testAuthorizedSetsOrderStatusToNew()
    {
        $orderStatus = new OrderStatus();
        $orderStatus->setId(OrderStatus::PENDING);

        $eccubeOrder = new Order();
        $eccubeOrder->setId(91);
        $eccubeOrder->setOrderStatus($orderStatus);

        $komojuOrder = new KomojuOrder();
        $komojuOrder->setOrder($eccubeOrder);

        $this->komojuOrderRepo->method('findOneBy')->willReturn($komojuOrder);

        $newStatus = new OrderStatus();
        $newStatus->setId(OrderStatus::NEW);
        $this->entityManager->method('find')->willReturn($newStatus);

        $object = $this->makeWebhookObject('pay_auth_2');
        $this->service->paymentAuthorized($object);

        $this->assertEquals(OrderStatus::NEW, $eccubeOrder->getOrderStatus()->getId());
    }

    public function testAuthorizedSkipsStatusChangeIfAlreadyNew()
    {
        $orderStatus = new OrderStatus();
        $orderStatus->setId(OrderStatus::NEW);

        $eccubeOrder = new Order();
        $eccubeOrder->setId(92);
        $eccubeOrder->setOrderStatus($orderStatus);

        $komojuOrder = new KomojuOrder();
        $komojuOrder->setOrder($eccubeOrder);
        $komojuOrder->setKomojuPaymentId('pay_already_set');

        $this->komojuOrderRepo->method('findOneBy')->willReturn($komojuOrder);
        $this->entityManager->method('find')->willReturn($orderStatus);

        $object = $this->makeWebhookObject('pay_already_set');
        $this->service->paymentAuthorized($object);

        // Status should remain NEW (not changed to something else)
        $this->assertEquals(OrderStatus::NEW, $eccubeOrder->getOrderStatus()->getId());
    }

    public function testAuthorizedFallbackToMetadata()
    {
        $orderStatus = new OrderStatus();
        $orderStatus->setId(OrderStatus::PENDING);

        $eccubeOrder = new Order();
        $eccubeOrder->setId(93);
        $eccubeOrder->setOrderStatus($orderStatus);

        $komojuOrder = new KomojuOrder();
        $komojuOrder->setOrder($eccubeOrder);

        $orderRepo = $this->createMock(StubRepository::class);
        $orderRepo->method('find')->willReturn($eccubeOrder);

        // findOneBy: payment_id lookup returns null, Order lookup returns the record
        $komojuRepo = $this->createMock(StubRepository::class);
        $komojuRepo->method('findOneBy')->willReturnCallback(function ($criteria, $orderBy = null) use ($komojuOrder) {
            if (isset($criteria['komoju_payment_id'])) {
                return null;
            }
            if (isset($criteria['Order'])) {
                return $komojuOrder;
            }
            return null;
        });

        $em = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);
        $em->method('getRepository')->willReturnCallback(function ($class) use ($orderRepo, $komojuRepo) {
            if ($class === Order::class) return $orderRepo;
            if ($class === KomojuOrder::class) return $komojuRepo;
            return $this->createMock(StubRepository::class);
        });

        $newStatus = new OrderStatus();
        $newStatus->setId(OrderStatus::NEW);
        $em->method('find')->willReturn($newStatus);
        $em->method('persist')->willReturn(null);
        $em->method('flush')->willReturn(null);

        $service = new WebhookService(
            $em,
            $this->orderStateMachine,
            $this->logService
        );

        $object = (object) ['data' => (object) [
            'id' => 'pay_new_1',
            'metadata' => (object) ['eccube_order_id' => '93'],
            'payment_details' => (object) ['type' => 'bank_transfer'],
        ]];

        $service->paymentAuthorized($object);

        $this->assertEquals('pay_new_1', $komojuOrder->getKomojuPaymentId());
        $this->assertEquals('bank_transfer', $komojuOrder->getType());
    }

    // --- partial refund handling ---

    public function testRefundedPartialUpdatesAmount()
    {
        $orderStatus = new OrderStatus();
        $orderStatus->setId(OrderStatus::PAID);

        $eccubeOrder = new Order();
        $eccubeOrder->setId(94);
        $eccubeOrder->setPaymentTotal(1000);
        $eccubeOrder->setOrderStatus($orderStatus);

        $komojuOrder = new KomojuOrder();
        $komojuOrder->setOrder($eccubeOrder);
        $komojuOrder->setKomojuPaymentId('pay_partial_1');

        $this->komojuOrderRepo->method('findOneBy')->willReturn($komojuOrder);

        $object = $this->makeWebhookObject('pay_partial_1', [
            'refunds' => [(object)['id' => 'ref_1', 'amount' => 300]]
        ]);

        $this->service->paymentRefunded($object);

        $this->assertEquals(300, $komojuOrder->getRefundedAmount());
        $this->assertEquals('ref_1', $komojuOrder->getRefundId());
    }

    public function testRefundedPartialDoesNotCancel()
    {
        $orderStatus = new OrderStatus();
        $orderStatus->setId(OrderStatus::PAID);

        $eccubeOrder = new Order();
        $eccubeOrder->setId(95);
        $eccubeOrder->setPaymentTotal(1000);
        $eccubeOrder->setOrderStatus($orderStatus);

        $komojuOrder = new KomojuOrder();
        $komojuOrder->setOrder($eccubeOrder);
        $komojuOrder->setKomojuPaymentId('pay_partial_2');

        $this->komojuOrderRepo->method('findOneBy')->willReturn($komojuOrder);
        $this->orderStateMachine->expects($this->never())->method('apply');

        $object = $this->makeWebhookObject('pay_partial_2', [
            'refunds' => [(object)['id' => 'ref_1', 'amount' => 300]]
        ]);

        $this->service->paymentRefunded($object);

        $this->assertEquals(OrderStatus::PAID, $eccubeOrder->getOrderStatus()->getId());
    }

    public function testRefundedFullCancelsOrder()
    {
        $orderStatus = new OrderStatus();
        $orderStatus->setId(OrderStatus::PAID);

        $eccubeOrder = new Order();
        $eccubeOrder->setId(96);
        $eccubeOrder->setPaymentTotal(1000);
        $eccubeOrder->setOrderStatus($orderStatus);

        $komojuOrder = new KomojuOrder();
        $komojuOrder->setOrder($eccubeOrder);
        $komojuOrder->setKomojuPaymentId('pay_full_1');

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
        $this->orderStateMachine->expects($this->once())->method('apply');

        $this->service = new WebhookService(
            $this->entityManager,
            $this->orderStateMachine,
            $this->logService
        );

        $object = $this->makeWebhookObject('pay_full_1', [
            'refunds' => [
                (object)['id' => 'ref_1', 'amount' => 500],
                (object)['id' => 'ref_2', 'amount' => 500],
            ]
        ]);

        $this->service->paymentRefunded($object);

        $this->assertEquals(1000, $komojuOrder->getRefundedAmount());
    }

    public function testRefundedSkipsLogWhenAmountUnchanged()
    {
        $orderStatus = new OrderStatus();
        $orderStatus->setId(OrderStatus::PAID);

        $eccubeOrder = new Order();
        $eccubeOrder->setId(97);
        $eccubeOrder->setPaymentTotal(1000);
        $eccubeOrder->setOrderStatus($orderStatus);

        $komojuOrder = new KomojuOrder();
        $komojuOrder->setOrder($eccubeOrder);
        $komojuOrder->setKomojuPaymentId('pay_dup_1');
        $komojuOrder->setRefundId('ref_1');
        $komojuOrder->setRefundedAmount(500); // already recorded

        $this->komojuOrderRepo->method('findOneBy')->willReturn($komojuOrder);

        // writeLog should NOT be called for the refund (only amount unchanged)
        $this->logService->expects($this->never())->method('writeLog');

        $object = $this->makeWebhookObject('pay_dup_1', [
            'refunds' => [(object)['id' => 'ref_1', 'amount' => 500]]
        ]);

        $this->service->paymentRefunded($object);
    }

    public function testRefundedLogsWhenAmountIncreases()
    {
        $orderStatus = new OrderStatus();
        $orderStatus->setId(OrderStatus::PAID);

        $eccubeOrder = new Order();
        $eccubeOrder->setId(98);
        $eccubeOrder->setPaymentTotal(1000);
        $eccubeOrder->setOrderStatus($orderStatus);

        $komojuOrder = new KomojuOrder();
        $komojuOrder->setOrder($eccubeOrder);
        $komojuOrder->setKomojuPaymentId('pay_inc_1');
        $komojuOrder->setRefundId('ref_1');
        $komojuOrder->setRefundedAmount(300); // previous partial

        $this->komojuOrderRepo->method('findOneBy')->willReturn($komojuOrder);

        // writeLog should be called with the new refund amount (200)
        $this->logService->expects($this->once())
            ->method('writeLog')
            ->with(
                $this->equalTo('webhook[refund]'),
                $this->equalTo(98),
                $this->equalTo('refund confirmed (amount=200)'),
                $this->equalTo(true)
            );

        $object = $this->makeWebhookObject('pay_inc_1', [
            'refunds' => [
                (object)['id' => 'ref_1', 'amount' => 300],
                (object)['id' => 'ref_2', 'amount' => 200],
            ]
        ]);

        $this->service->paymentRefunded($object);

        $this->assertEquals(500, $komojuOrder->getRefundedAmount());
    }
}
