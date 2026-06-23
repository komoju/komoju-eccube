<?php

namespace Tests\Komoju\Service;

use Plugin\Komoju\Entity\KomojuOrder;
use Plugin\Komoju\Service\LogService;
use Plugin\Komoju\Service\WebhookService;
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
    private $connection;
    private $service;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);
        $this->orderStateMachine = $this->createMock(OrderStateMachine::class);
        $this->logService = $this->createMock(LogService::class);
        $this->komojuOrderRepo = $this->createMock(StubRepository::class);

        // The refund path performs an atomic compare-and-swap UPDATE via the
        // DBAL connection and logs only when it affects >= 1 row. Default the
        // connection so that the CAS "claims" the refund (affected = 1); tests
        // that exercise the duplicate/no-op case override this to return 0.
        $this->connection = $this->createMock(\Doctrine\DBAL\Connection::class);
        $this->connection->method('executeStatement')->willReturn(1);
        $this->entityManager->method('getConnection')->willReturn($this->connection);

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

        // The CAS UPDATE matches zero rows because the stored refund_id already
        // equals this payload's id set — i.e. a duplicate delivery. Model that
        // by having executeStatement() report 0 affected rows.
        $conn = $this->createMock(\Doctrine\DBAL\Connection::class);
        $conn->method('executeStatement')->willReturn(0);
        $this->entityManager = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);
        $this->entityManager->method('getConnection')->willReturn($conn);
        $this->entityManager->method('getRepository')
            ->willReturnCallback(function ($class) {
                if ($class === KomojuOrder::class) return $this->komojuOrderRepo;
                return $this->createMock(StubRepository::class);
            });
        $this->service = new WebhookService(
            $this->entityManager,
            $this->orderStateMachine,
            $this->logService
        );

        // writeLog should NOT be called for the refund (duplicate / no-op claim)
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

    // --- duplicate refund webhook handling ---
    //
    // KOMOJU emits two event types for a single refund (payment.refunded and
    // payment.refund.created), both routed to paymentRefunded(). They carry the
    // same refund id(s). The handler must log the refund exactly once. The
    // dedupe is decided by an atomic compare-and-swap UPDATE: it logs only when
    // the UPDATE affects >= 1 row (the refund state actually changed).

    public function testRefundedDuplicateEventLogsOnce()
    {
        $orderStatus = new OrderStatus();
        $orderStatus->setId(OrderStatus::PAID);

        $eccubeOrder = new Order();
        $eccubeOrder->setId(120);
        $eccubeOrder->setPaymentTotal(6500);
        $eccubeOrder->setOrderStatus($orderStatus);

        $komojuOrder = new KomojuOrder();
        $komojuOrder->setOrder($eccubeOrder);
        $komojuOrder->setKomojuPaymentId('pay_dup');

        // Model the CAS UPDATE: the first delivery changes the row (1 affected),
        // the second is a duplicate and changes nothing (0 affected).
        $conn = $this->createMock(\Doctrine\DBAL\Connection::class);
        $conn->method('executeStatement')->willReturnOnConsecutiveCalls(1, 0);

        $cancelStatus = new OrderStatus();
        $cancelStatus->setId(OrderStatus::CANCEL);
        $statusRepo = $this->createMock(StubRepository::class);
        $statusRepo->method('find')->willReturn($cancelStatus);

        $this->entityManager = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);
        $this->entityManager->method('getConnection')->willReturn($conn);
        $this->entityManager->method('getRepository')
            ->willReturnCallback(function ($class) use ($statusRepo) {
                if ($class === OrderStatus::class) return $statusRepo;
                if ($class === KomojuOrder::class) return $this->komojuOrderRepo;
                return $this->createMock(StubRepository::class);
            });
        $this->komojuOrderRepo->method('findOneBy')->willReturn($komojuOrder);
        $this->orderStateMachine->method('can')->willReturn(true);

        $this->service = new WebhookService(
            $this->entityManager,
            $this->orderStateMachine,
            $this->logService
        );

        // The refund log line must be written exactly once across BOTH events,
        // even though both deliveries carry the same refund id 'ref_dup'.
        $refundLogCalls = 0;
        $this->logService->method('writeLog')
            ->willReturnCallback(function ($api, $orderId, $msg) use (&$refundLogCalls) {
                if ($api === 'webhook[refund]' && strpos($msg, 'refund confirmed') === 0) {
                    $refundLogCalls++;
                }
            });

        $payload = ['refunds' => [(object)['id' => 'ref_dup', 'amount' => 6500]]];

        // First event (e.g. payment.refunded) -> CAS affects 1 row -> logs.
        $this->service->paymentRefunded($this->makeWebhookObject('pay_dup', $payload));
        // Second event (e.g. payment.refund.created) -> CAS affects 0 -> skips.
        $this->service->paymentRefunded($this->makeWebhookObject('pay_dup', $payload));

        $this->assertEquals(1, $refundLogCalls, 'refund must be logged exactly once across duplicate events');
        $this->assertEquals(6500, $komojuOrder->getRefundedAmount());
        $this->assertEquals('ref_dup', $komojuOrder->getRefundId());
    }

    public function testRefundedConcurrentDuplicateDoesNotDoubleLog()
    {
        // Models the real production race directly at the CAS layer: two
        // separate requests, each with its OWN freshly-loaded (stale) copy of
        // the KomojuOrder, both with refund_id=''. Whoever's UPDATE commits
        // first affects 1 row (and logs); the other's UPDATE re-evaluates its
        // WHERE against the now-committed row, matches 0 rows, and skips — with
        // NO dependence on FOR UPDATE / refresh() / isolation level. This is
        // what makes the fix correct on MySQL, PostgreSQL and SQLite alike.
        $orderStatus = new OrderStatus();
        $orderStatus->setId(OrderStatus::PAID);

        $eccubeOrder = new Order();
        $eccubeOrder->setId(123);
        $eccubeOrder->setPaymentTotal(6500);
        $eccubeOrder->setOrderStatus($orderStatus);

        $firstOrder = new KomojuOrder();
        $firstOrder->setOrder($eccubeOrder);
        $firstOrder->setKomojuPaymentId('pay_race');

        $secondOrder = new KomojuOrder();   // separate stale copy, refund_id=''
        $secondOrder->setOrder($eccubeOrder);
        $secondOrder->setKomojuPaymentId('pay_race');

        $cancelStatus = new OrderStatus();
        $cancelStatus->setId(OrderStatus::CANCEL);
        $statusRepo = $this->createMock(StubRepository::class);
        $statusRepo->method('find')->willReturn($cancelStatus);
        $this->orderStateMachine->method('can')->willReturn(true);

        $refundLogCalls = 0;
        $this->logService->method('writeLog')
            ->willReturnCallback(function ($api, $orderId, $msg) use (&$refundLogCalls) {
                if ($api === 'webhook[refund]' && strpos($msg, 'refund confirmed') === 0) {
                    $refundLogCalls++;
                }
            });

        $payload = ['refunds' => [(object)['id' => 'ref_race', 'amount' => 6500]]];

        // First handler: CAS wins (1 affected).
        $conn1 = $this->createMock(\Doctrine\DBAL\Connection::class);
        $conn1->method('executeStatement')->willReturn(1);
        $em1 = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);
        $em1->method('getConnection')->willReturn($conn1);
        $repo1 = $this->createMock(StubRepository::class);
        $repo1->method('findOneBy')->willReturn($firstOrder);
        $em1->method('getRepository')->willReturnCallback(function ($class) use ($statusRepo, $repo1) {
            if ($class === OrderStatus::class) return $statusRepo;
            if ($class === KomojuOrder::class) return $repo1;
            return $this->createMock(StubRepository::class);
        });
        (new WebhookService($em1, $this->orderStateMachine, $this->logService))
            ->paymentRefunded($this->makeWebhookObject('pay_race', $payload));

        // Second handler: CAS loses — row already has this id set (0 affected).
        $conn2 = $this->createMock(\Doctrine\DBAL\Connection::class);
        $conn2->method('executeStatement')->willReturn(0);
        $em2 = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);
        $em2->method('getConnection')->willReturn($conn2);
        $repo2 = $this->createMock(StubRepository::class);
        $repo2->method('findOneBy')->willReturn($secondOrder);
        $em2->method('getRepository')->willReturnCallback(function ($class) use ($statusRepo, $repo2) {
            if ($class === OrderStatus::class) return $statusRepo;
            if ($class === KomojuOrder::class) return $repo2;
            return $this->createMock(StubRepository::class);
        });
        (new WebhookService($em2, $this->orderStateMachine, $this->logService))
            ->paymentRefunded($this->makeWebhookObject('pay_race', $payload));

        $this->assertEquals(1, $refundLogCalls, 'concurrent duplicate must not double-log');
    }

    public function testRefundedClaimsViaCompareAndSwapUpdate()
    {
        // The dedupe must be driven by an atomic UPDATE on plg_komoju_order
        // whose WHERE excludes rows already holding this refund id set, so the
        // database (not stale in-memory state) decides if the refund is new.
        $orderStatus = new OrderStatus();
        $orderStatus->setId(OrderStatus::PAID);

        $eccubeOrder = new Order();
        $eccubeOrder->setId(121);
        $eccubeOrder->setPaymentTotal(1000);
        $eccubeOrder->setOrderStatus($orderStatus);

        $komojuOrder = new KomojuOrder();
        $komojuOrder->setOrder($eccubeOrder);
        $komojuOrder->setKomojuPaymentId('pay_cas');

        $capturedSql = null;
        $conn = $this->createMock(\Doctrine\DBAL\Connection::class);
        $conn->method('executeStatement')
            ->willReturnCallback(function ($sql, $params) use (&$capturedSql) {
                $capturedSql = $sql;
                return 1;
            });
        $this->entityManager = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);
        $this->entityManager->method('getConnection')->willReturn($conn);
        $this->entityManager->method('getRepository')
            ->willReturnCallback(function ($class) {
                if ($class === KomojuOrder::class) return $this->komojuOrderRepo;
                return $this->createMock(StubRepository::class);
            });
        $this->komojuOrderRepo->method('findOneBy')->willReturn($komojuOrder);

        $this->service = new WebhookService(
            $this->entityManager,
            $this->orderStateMachine,
            $this->logService
        );

        $object = $this->makeWebhookObject('pay_cas', [
            'refunds' => [(object)['id' => 'ref_cas', 'amount' => 300]]
        ]);
        $this->service->paymentRefunded($object);

        $this->assertNotNull($capturedSql, 'a compare-and-swap UPDATE must be issued');
        $this->assertStringContainsString('UPDATE plg_komoju_order', $capturedSql);
        $this->assertStringContainsStringIgnoringCase('where', $capturedSql);
        $this->assertStringContainsStringIgnoringCase('refund_id', $capturedSql);
    }

    public function testRefundedNewPartialIdStillLogs()
    {
        // A genuinely new partial refund (new id) after a prior partial must
        // still be logged, and only for the newly-added amount.
        $orderStatus = new OrderStatus();
        $orderStatus->setId(OrderStatus::PAID);

        $eccubeOrder = new Order();
        $eccubeOrder->setId(122);
        $eccubeOrder->setPaymentTotal(1000);
        $eccubeOrder->setOrderStatus($orderStatus);

        $komojuOrder = new KomojuOrder();
        $komojuOrder->setOrder($eccubeOrder);
        $komojuOrder->setKomojuPaymentId('pay_partial');
        $komojuOrder->setRefundId('ref_a');          // already recorded
        $komojuOrder->setRefundedAmount(300);

        $this->komojuOrderRepo->method('findOneBy')->willReturn($komojuOrder);

        $this->logService->expects($this->once())
            ->method('writeLog')
            ->with(
                $this->equalTo('webhook[refund]'),
                $this->equalTo(122),
                $this->equalTo('refund confirmed (amount=200)'),
                $this->equalTo(true)
            );

        $object = $this->makeWebhookObject('pay_partial', [
            'refunds' => [
                (object)['id' => 'ref_a', 'amount' => 300],
                (object)['id' => 'ref_b', 'amount' => 200],
            ]
        ]);

        $this->service->paymentRefunded($object);

        $this->assertEquals(500, $komojuOrder->getRefundedAmount());
        $this->assertEquals('ref_a,ref_b', $komojuOrder->getRefundId());
    }
}
