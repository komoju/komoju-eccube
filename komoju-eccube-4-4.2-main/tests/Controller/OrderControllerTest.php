<?php

namespace Tests\Komoju42\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Eccube\Entity\Master\OrderStatus;
use Eccube\Entity\Order;
use Eccube\Repository\Master\OrderStatusRepository;
use Eccube\Repository\OrderRepository;
use Eccube\Service\OrderStateMachine;
use Plugin\Komoju42\Controller\Admin\OrderController;
use Plugin\Komoju42\Entity\KomojuOrder;
use Plugin\Komoju42\Repository\KomojuOrderRepository;
use Plugin\Komoju42\KomojuClient;
use Plugin\Komoju42\Service\ConfigService;
use Plugin\Komoju42\Service\KomojuClientFactory;
use Plugin\Komoju42\Service\LogService;
use Plugin\Komoju42\Service\MailExService;
use Symfony\Component\HttpFoundation\Request;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the admin capture path in OrderController::charge():
 *   - capture is blocked when the KOMOJU-authorized amount != order total,
 *   - a successful capture records captured_amount on the KomojuOrder.
 */
class OrderControllerTest extends TestCase
{
    private $em;
    private $orderStateMachine;
    private $orderRepo;
    private $orderStatusRepo;
    private $komojuOrderRepo;
    private $configService;
    private $logService;
    private $mailExService;
    private $clientFactory;
    private $komojuClient;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->orderStateMachine = $this->createMock(OrderStateMachine::class);
        $this->orderRepo = $this->createMock(OrderRepository::class);
        $this->orderStatusRepo = $this->createMock(OrderStatusRepository::class);
        $this->komojuOrderRepo = $this->createMock(KomojuOrderRepository::class);
        $this->configService = $this->createMock(ConfigService::class);
        $this->logService = $this->createMock(LogService::class);
        $this->mailExService = $this->createMock(MailExService::class);
        $this->clientFactory = $this->createMock(KomojuClientFactory::class);
        $this->komojuClient = $this->createMock(KomojuClient::class);

        $this->configService->method('getConfigData')->willReturn(['secret_key' => 'sk_test']);
        $this->clientFactory->method('create')->willReturn($this->komojuClient);
    }

    private function makeController(): OrderController
    {
        return new OrderController(
            $this->em,
            $this->orderStateMachine,
            $this->orderRepo,
            $this->orderStatusRepo,
            $this->komojuOrderRepo,
            $this->configService,
            $this->logService,
            $this->mailExService,
            $this->clientFactory
        );
    }

    private function arrangeOrder(int $total): array
    {
        $status = new OrderStatus();
        $status->setId(OrderStatus::NEW);

        $order = new Order();
        $order->setId(2);
        $order->setOrderStatus($status);
        $order->setPaymentTotal($total);

        $komojuOrder = new KomojuOrder();
        $komojuOrder->setOrder($order);
        $komojuOrder->setKomojuPaymentId('pay_1');

        $this->orderRepo->method('find')->willReturn($order);
        $this->komojuOrderRepo->method('findOneBy')->willReturn($komojuOrder);

        return [$order, $komojuOrder];
    }

    public function testCaptureBlockedOnAmountMismatch()
    {
        [$order, $komojuOrder] = $this->arrangeOrder(7160);

        // KOMOJU authorized only 4080, order total is 7160.
        $this->komojuClient->method('getStatusCode')->willReturn(200);
        $this->komojuClient->method('getPayment')->willReturn([
            'status' => 'authorized',
            'amount' => 4080,
        ]);

        // Capture must NOT be attempted.
        $this->komojuClient->expects($this->never())->method('capturePayment');

        $this->logService->expects($this->once())
            ->method('writeLog')
            ->with('capture', 2, $this->stringContains('blocked'));

        $request = new Request();
        $request->request->set('_token', 'x');

        $controller = $this->makeController();
        $controller->charge($request, 2);

        $this->assertNull($komojuOrder->getCapturedAmount());
    }

    public function testCaptureRecordsCapturedAmountOnSuccess()
    {
        [$order, $komojuOrder] = $this->arrangeOrder(4080);

        $paidStatus = new OrderStatus();
        $paidStatus->setId(OrderStatus::PAID);
        $this->orderStatusRepo->method('find')->willReturn($paidStatus);

        $this->komojuClient->method('getStatusCode')->willReturn(200);
        // First getPayment() returns authorized+amount; capturePayment() returns captured.
        $this->komojuClient->method('getPayment')->willReturn([
            'status' => 'authorized',
            'amount' => 4080,
        ]);
        $this->komojuClient->method('capturePayment')->willReturn([
            'status' => 'captured',
            'captured_at' => '2026-06-26T11:32:00Z',
            'amount' => 4080,
        ]);

        $request = new Request();
        $request->request->set('_token', 'x');

        $controller = $this->makeController();
        $controller->charge($request, 2);

        $this->assertEquals(4080, $komojuOrder->getCapturedAmount());
        $this->assertNotNull($komojuOrder->getCapturedAt());
    }
}
