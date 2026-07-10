<?php

namespace Tests\Komoju\Service;

use Plugin\Komoju\Entity\KomojuPay;
use Plugin\Komoju\Entity\KomojuOrder;
use Plugin\Komoju\Service\ConfigService;
use Plugin\Komoju\Service\LogService;
use Plugin\Komoju\Service\KomojuClientFactory;
use Plugin\Komoju\Service\Method\KomojuPayment;
use Plugin\Komoju\KomojuClient;
use Eccube\Common\EccubeConfig;
use Eccube\Entity\Order;
use Eccube\Entity\Payment;
use Eccube\Entity\Master\OrderStatus;
use Eccube\Repository\Master\OrderStatusRepository;
use Eccube\Service\Payment\PaymentResult;
use Eccube\Service\PurchaseFlow\PurchaseFlow;
use Eccube\Service\PurchaseFlow\PurchaseException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use PHPUnit\Framework\TestCase;

class KomojuPaymentTest extends TestCase
{
    private $entityManager;
    private $configService;
    private $logService;
    private $clientFactory;
    private $orderStatusRepo;
    private $requestStack;
    private $router;
    private $purchaseFlow;
    private $payment;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);
        $this->configService = $this->createMock(ConfigService::class);
        $this->logService = $this->createMock(LogService::class);
        $this->clientFactory = $this->createMock(KomojuClientFactory::class);
        $this->orderStatusRepo = $this->createMock(OrderStatusRepository::class);
        $this->requestStack = new RequestStack(new Request());
        $this->router = $this->createMock(UrlGeneratorInterface::class);
        $this->purchaseFlow = $this->createMock(PurchaseFlow::class);

        $this->payment = new KomojuPayment(
            new EccubeConfig(),
            $this->entityManager,
            $this->purchaseFlow,
            $this->orderStatusRepo,
            $this->requestStack,
            $this->configService,
            $this->logService,
            $this->router,
            $this->clientFactory
        );
    }

    private function makeOrder($paymentTotal = 1000, ?Payment $payment = null): Order
    {
        $order = new Order();
        $order->setId(1);
        $order->setPaymentTotal($paymentTotal);
        $order->setOrderNo('TEST-001');
        $order->setCurrencyCode('JPY');
        if ($payment) {
            $order->setPayment($payment);
        } else {
            $p = new Payment();
            $p->setId(10);
            $order->setPayment($p);
        }
        return $order;
    }

    // --- verify ---

    public function testVerifySuccess()
    {
        $order = $this->makeOrder(1000);
        $this->payment->setOrder($order);

        $this->configService->method('getConfigData')->willReturn(['secret_key' => 'sk_test']);

        $komojuPay = new KomojuPay();
        $komojuPay->setName('credit_card');

        $repo = $this->createMock(StubRepository::class);
        $repo->method('findOneBy')->willReturn($komojuPay);
        $this->entityManager->method('getRepository')->willReturn($repo);

        $result = $this->payment->verify();
        $this->assertTrue($result->getSuccess());
    }

    public function testVerifyNoSecretKey()
    {
        $order = $this->makeOrder();
        $this->payment->setOrder($order);

        $this->configService->method('getConfigData')->willReturn(['secret_key' => '']);

        $result = $this->payment->verify();
        $this->assertFalse($result->getSuccess());
        $this->assertNotEmpty($result->getErrors());
    }

    public function testVerifyNullSecretKey()
    {
        $order = $this->makeOrder();
        $this->payment->setOrder($order);

        $this->configService->method('getConfigData')->willReturn(['secret_key' => null]);

        $result = $this->payment->verify();
        $this->assertFalse($result->getSuccess());
    }

    public function testVerifyNoKomojuPay()
    {
        $order = $this->makeOrder();
        $this->payment->setOrder($order);

        $this->configService->method('getConfigData')->willReturn(['secret_key' => 'sk_test']);

        $repo = $this->createMock(StubRepository::class);
        $repo->method('findOneBy')->willReturn(null);
        $this->entityManager->method('getRepository')->willReturn($repo);

        $result = $this->payment->verify();
        $this->assertFalse($result->getSuccess());
    }

    public function testVerifyBelowMinimum()
    {
        $payment = new Payment();
        $payment->setId(10);
        $payment->setRuleMin(500);
        $payment->setRuleMax(null);

        $order = $this->makeOrder(100, $payment);
        $this->payment->setOrder($order);

        $this->configService->method('getConfigData')->willReturn(['secret_key' => 'sk_test']);

        $komojuPay = new KomojuPay();
        $repo = $this->createMock(StubRepository::class);
        $repo->method('findOneBy')->willReturn($komojuPay);
        $this->entityManager->method('getRepository')->willReturn($repo);

        $result = $this->payment->verify();
        $this->assertFalse($result->getSuccess());
        $this->assertContains('komoju_payment.shopping.verify.error.payment_total.too_small', $result->getErrors());
    }

    public function testVerifyAboveMaximum()
    {
        $payment = new Payment();
        $payment->setId(10);
        $payment->setRuleMin(null);
        $payment->setRuleMax(500);

        $order = $this->makeOrder(1000, $payment);
        $this->payment->setOrder($order);

        $this->configService->method('getConfigData')->willReturn(['secret_key' => 'sk_test']);

        $komojuPay = new KomojuPay();
        $repo = $this->createMock(StubRepository::class);
        $repo->method('findOneBy')->willReturn($komojuPay);
        $this->entityManager->method('getRepository')->willReturn($repo);

        $result = $this->payment->verify();
        $this->assertFalse($result->getSuccess());
        $this->assertContains('komoju_payment.shopping.verify.error.payment_total.too_much', $result->getErrors());
    }

    public function testVerifyExactMinimumPasses()
    {
        $payment = new Payment();
        $payment->setId(10);
        $payment->setRuleMin(1000);
        $payment->setRuleMax(null);

        $order = $this->makeOrder(1000, $payment);
        $this->payment->setOrder($order);

        $this->configService->method('getConfigData')->willReturn(['secret_key' => 'sk_test']);

        $komojuPay = new KomojuPay();
        $repo = $this->createMock(StubRepository::class);
        $repo->method('findOneBy')->willReturn($komojuPay);
        $this->entityManager->method('getRepository')->willReturn($repo);

        $result = $this->payment->verify();
        $this->assertTrue($result->getSuccess());
    }

    public function testVerifyNullMinMaxPasses()
    {
        $payment = new Payment();
        $payment->setId(10);
        $payment->setRuleMin(null);
        $payment->setRuleMax(null);

        $order = $this->makeOrder(99999, $payment);
        $this->payment->setOrder($order);

        $this->configService->method('getConfigData')->willReturn(['secret_key' => 'sk_test']);

        $komojuPay = new KomojuPay();
        $repo = $this->createMock(StubRepository::class);
        $repo->method('findOneBy')->willReturn($komojuPay);
        $this->entityManager->method('getRepository')->willReturn($repo);

        $result = $this->payment->verify();
        $this->assertTrue($result->getSuccess());
    }

    // --- checkout ---

    public function testCheckoutReturnsSuccess()
    {
        $result = $this->payment->checkout();
        $this->assertTrue($result->getSuccess());
    }

    // --- apply ---

    public function testApplySuccess()
    {
        $order = $this->makeOrder(2000);
        $this->payment->setOrder($order);

        $pendingStatus = new OrderStatus();
        $pendingStatus->setId(OrderStatus::PENDING);

        $this->orderStatusRepo->method('find')->willReturn($pendingStatus);

        $this->configService->method('getConfigData')->willReturn([
            'secret_key' => 'sk_test',
            'capture_on' => true,
            'order_number_format' => null,
        ]);

        $komojuPay = new KomojuPay();
        $komojuPay->setName('credit_card');

        $repo = $this->createMock(StubRepository::class);
        $repo->method('findOneBy')->willReturn($komojuPay);
        $this->entityManager->method('getRepository')->willReturn($repo);

        $this->router->method('generate')->willReturn('https://shop.test/return');

        $client = $this->createMock(KomojuClient::class);
        $client->method('createSession')->willReturn([
            'id' => 'ses_abc123',
            'session_url' => 'https://komoju.com/sessions/ses_abc123',
        ]);
        $client->method('getStatusCode')->willReturn(200);
        $this->clientFactory->method('create')->willReturn($client);

        $this->entityManager->expects($this->atLeastOnce())->method('persist');

        $dispatcher = $this->payment->apply();

        $this->assertNotNull($dispatcher);
        $response = $dispatcher->getResponse();
        $this->assertEquals('https://komoju.com/sessions/ses_abc123', $response->getTargetUrl());
    }

    public function testApplyApiFailureThrowsException()
    {
        $order = $this->makeOrder(2000);
        $this->payment->setOrder($order);

        $pendingStatus = new OrderStatus();
        $pendingStatus->setId(OrderStatus::PENDING);
        $processingStatus = new OrderStatus();
        $processingStatus->setId(OrderStatus::PROCESSING);

        $this->orderStatusRepo->method('find')->willReturnCallback(function ($id) use ($pendingStatus, $processingStatus) {
            return $id === OrderStatus::PENDING ? $pendingStatus : $processingStatus;
        });

        $this->configService->method('getConfigData')->willReturn([
            'secret_key' => 'sk_test',
            'capture_on' => true,
            'order_number_format' => null,
        ]);

        $komojuPay = new KomojuPay();
        $komojuPay->setName('credit_card');

        $repo = $this->createMock(StubRepository::class);
        $repo->method('findOneBy')->willReturn($komojuPay);
        $this->entityManager->method('getRepository')->willReturn($repo);

        $this->router->method('generate')->willReturn('https://shop.test/return');

        $client = $this->createMock(KomojuClient::class);
        $client->method('createSession')->willReturn([]);
        $client->method('getStatusCode')->willReturn(400);
        $client->method('getLastError')->willReturn('invalid_request');
        $this->clientFactory->method('create')->willReturn($client);

        $this->expectException(PurchaseException::class);
        $this->payment->apply();
    }

    public function testApplySessionMissingIdThrowsException()
    {
        $order = $this->makeOrder(2000);
        $this->payment->setOrder($order);

        $pendingStatus = new OrderStatus();
        $pendingStatus->setId(OrderStatus::PENDING);
        $processingStatus = new OrderStatus();
        $processingStatus->setId(OrderStatus::PROCESSING);

        $this->orderStatusRepo->method('find')->willReturnCallback(function ($id) use ($pendingStatus, $processingStatus) {
            return $id === OrderStatus::PENDING ? $pendingStatus : $processingStatus;
        });

        $this->configService->method('getConfigData')->willReturn([
            'secret_key' => 'sk_test',
            'capture_on' => false,
            'order_number_format' => null,
        ]);

        $komojuPay = new KomojuPay();
        $komojuPay->setName('konbini');

        $repo = $this->createMock(StubRepository::class);
        $repo->method('findOneBy')->willReturn($komojuPay);
        $this->entityManager->method('getRepository')->willReturn($repo);

        $this->router->method('generate')->willReturn('https://shop.test/return');

        $client = $this->createMock(KomojuClient::class);
        $client->method('createSession')->willReturn(['something' => 'but_no_id']);
        $client->method('getStatusCode')->willReturn(200);
        $client->method('getLastError')->willReturn(null);
        $this->clientFactory->method('create')->willReturn($client);

        $this->expectException(PurchaseException::class);
        $this->payment->apply();
    }

    public function testApplySendsCorrectPaymentType()
    {
        $order = $this->makeOrder(3000);
        $this->payment->setOrder($order);

        $pendingStatus = new OrderStatus();
        $this->orderStatusRepo->method('find')->willReturn($pendingStatus);

        $this->configService->method('getConfigData')->willReturn([
            'secret_key' => 'sk_test',
            'capture_on' => false,
            'order_number_format' => null,
        ]);

        $komojuPay = new KomojuPay();
        $komojuPay->setName('paypay');

        $repo = $this->createMock(StubRepository::class);
        $repo->method('findOneBy')->willReturn($komojuPay);
        $this->entityManager->method('getRepository')->willReturn($repo);

        $this->router->method('generate')->willReturn('https://shop.test/return');

        $client = $this->createMock(KomojuClient::class);
        $client->method('getStatusCode')->willReturn(200);
        $client->expects($this->once())
            ->method('createSession')
            ->with($this->callback(function ($data) {
                return $data['payment_types'] === ['paypay']
                    && $data['payment_data']['capture'] === 'manual'
                    && $data['amount'] === 3000
                    && $data['currency'] === 'JPY';
            }))
            ->willReturn(['id' => 'ses_1', 'session_url' => 'https://komoju.com/s/1']);
        $this->clientFactory->method('create')->willReturn($client);

        $this->payment->apply();
    }

    public function testApplyIgnoresStoredOrderNumberFormat()
    {
        // The order-number customization feature was removed: external_order_num
        // always uses the fixed ECC-{order_id} format regardless of any legacy
        // order_number_format value still stored in the DB. The internal order
        // id guarantees uniqueness per session.
        $order = $this->makeOrder(1000);
        $order->setOrderNo('ORD-999');
        $order->setId(55);
        $this->payment->setOrder($order);

        $pendingStatus = new OrderStatus();
        $this->orderStatusRepo->method('find')->willReturn($pendingStatus);

        $this->configService->method('getConfigData')->willReturn([
            'secret_key' => 'sk_test',
            'capture_on' => true,
            // Legacy value that must be ignored.
            'order_number_format' => 'SHOP-{order_no}',
        ]);

        $komojuPay = new KomojuPay();
        $komojuPay->setName('credit_card');

        $repo = $this->createMock(StubRepository::class);
        $repo->method('findOneBy')->willReturn($komojuPay);
        $this->entityManager->method('getRepository')->willReturn($repo);

        $this->router->method('generate')->willReturn('https://shop.test/return');

        $client = $this->createMock(KomojuClient::class);
        $client->method('getStatusCode')->willReturn(200);
        $client->expects($this->once())
            ->method('createSession')
            ->with($this->callback(function ($data) {
                return $data['payment_data']['external_order_num'] === 'ECC-55';
            }))
            ->willReturn(['id' => 'ses_1', 'session_url' => 'https://komoju.com/s/1']);
        $this->clientFactory->method('create')->willReturn($client);

        $this->payment->apply();
    }

    public function testApplyDefaultCurrencyWhenEmpty()
    {
        $order = $this->makeOrder(1000);
        $order->setCurrencyCode('');
        $this->payment->setOrder($order);

        $pendingStatus = new OrderStatus();
        $this->orderStatusRepo->method('find')->willReturn($pendingStatus);

        $this->configService->method('getConfigData')->willReturn([
            'secret_key' => 'sk_test',
            'capture_on' => true,
            'order_number_format' => null,
        ]);

        $komojuPay = new KomojuPay();
        $komojuPay->setName('credit_card');

        $repo = $this->createMock(StubRepository::class);
        $repo->method('findOneBy')->willReturn($komojuPay);
        $this->entityManager->method('getRepository')->willReturn($repo);

        $this->router->method('generate')->willReturn('https://shop.test/return');

        $client = $this->createMock(KomojuClient::class);
        $client->method('getStatusCode')->willReturn(200);
        $client->expects($this->once())
            ->method('createSession')
            ->with($this->callback(function ($data) {
                return $data['currency'] === 'JPY';
            }))
            ->willReturn(['id' => 'ses_1', 'session_url' => 'https://komoju.com/s/1']);
        $this->clientFactory->method('create')->willReturn($client);

        $this->payment->apply();
    }

    // --- generateUniqueOrderNumber (retry suffix) ---

    public function testApplyFirstAttemptUsesBaseOrderNumber()
    {
        $order = $this->makeOrder(1000);
        $order->setOrderNo('100');
        $this->payment->setOrder($order);

        $pendingStatus = new OrderStatus();
        $pendingStatus->setId(OrderStatus::PENDING);
        $this->orderStatusRepo->method('find')->willReturn($pendingStatus);

        $this->configService->method('getConfigData')->willReturn([
            'secret_key' => 'sk_test',
            'capture_on' => true,
            'order_number_format' => null,
        ]);

        $komojuPay = new KomojuPay();
        $komojuPay->setName('paypay');

        $repo = $this->createMock(StubRepository::class);
        $repo->method('findOneBy')->willReturn($komojuPay);
        $repo->method('count')->willReturn(0);
        $this->entityManager->method('getRepository')->willReturn($repo);
        $this->router->method('generate')->willReturn('https://shop.test/return');

        $client = $this->createMock(KomojuClient::class);
        $client->method('getStatusCode')->willReturn(200);
        $client->expects($this->once())
            ->method('createSession')
            ->with($this->callback(function ($data) {
                // Fixed format ECC-{order_id}; makeOrder id is 1.
                return $data['payment_data']['external_order_num'] === 'ECC-1';
            }))
            ->willReturn(['id' => 'ses_1', 'session_url' => 'https://komoju.com/s/1']);
        $this->clientFactory->method('create')->willReturn($client);

        $this->payment->apply();
    }

    public function testApplyRetryAppendsCountSuffix()
    {
        $order = $this->makeOrder(1000);
        $order->setOrderNo('100');
        $this->payment->setOrder($order);

        $pendingStatus = new OrderStatus();
        $pendingStatus->setId(OrderStatus::PENDING);
        $this->orderStatusRepo->method('find')->willReturn($pendingStatus);

        $this->configService->method('getConfigData')->willReturn([
            'secret_key' => 'sk_test',
            'capture_on' => true,
            'order_number_format' => null,
        ]);

        $komojuPay = new KomojuPay();
        $komojuPay->setName('paypay');

        $repo = $this->createMock(StubRepository::class);
        $repo->method('findOneBy')->willReturn($komojuPay);
        $repo->method('count')->willReturn(1); // one previous attempt
        $this->entityManager->method('getRepository')->willReturn($repo);
        $this->router->method('generate')->willReturn('https://shop.test/return');

        $client = $this->createMock(KomojuClient::class);
        $client->method('getStatusCode')->willReturn(200);
        $client->expects($this->once())
            ->method('createSession')
            ->with($this->callback(function ($data) {
                // Fixed base ECC-{order_id} (id 1), then random retry token,
                // e.g. "ECC-1-a1b2c".
                return preg_match('/^ECC-1-[0-9a-f]+$/', $data['payment_data']['external_order_num']) === 1;
            }))
            ->willReturn(['id' => 'ses_1', 'session_url' => 'https://komoju.com/s/1']);
        $this->clientFactory->method('create')->willReturn($client);

        $this->payment->apply();
    }

    public function testApplyMultipleRetriesIncrementSuffix()
    {
        $order = $this->makeOrder(1000);
        $order->setOrderNo('100');
        $this->payment->setOrder($order);

        $pendingStatus = new OrderStatus();
        $pendingStatus->setId(OrderStatus::PENDING);
        $this->orderStatusRepo->method('find')->willReturn($pendingStatus);

        $this->configService->method('getConfigData')->willReturn([
            'secret_key' => 'sk_test',
            'capture_on' => true,
            'order_number_format' => null,
        ]);

        $komojuPay = new KomojuPay();
        $komojuPay->setName('paypay');

        $repo = $this->createMock(StubRepository::class);
        $repo->method('findOneBy')->willReturn($komojuPay);
        $repo->method('count')->willReturn(3); // three previous attempts
        $this->entityManager->method('getRepository')->willReturn($repo);
        $this->router->method('generate')->willReturn('https://shop.test/return');

        $client = $this->createMock(KomojuClient::class);
        $client->method('getStatusCode')->willReturn(200);
        $client->expects($this->once())
            ->method('createSession')
            ->with($this->callback(function ($data) {
                // Fixed base ECC-{order_id} (id 1), then random retry token,
                // e.g. "ECC-1-a1b2c".
                return preg_match('/^ECC-1-[0-9a-f]+$/', $data['payment_data']['external_order_num']) === 1;
            }))
            ->willReturn(['id' => 'ses_1', 'session_url' => 'https://komoju.com/s/1']);
        $this->clientFactory->method('create')->willReturn($client);

        $this->payment->apply();
    }

    public function testApplyRetryUsesFixedFormatBase()
    {
        // A retry uses the fixed base ECC-{order_id} (customization removed)
        // and only appends a random token AFTER the base, e.g.
        // "ECC-55" -> "ECC-55-a1b2c". Any legacy order_number_format is ignored.
        $order = $this->makeOrder(1000);
        $order->setOrderNo('100');
        $order->setId(55);
        $this->payment->setOrder($order);

        $pendingStatus = new OrderStatus();
        $pendingStatus->setId(OrderStatus::PENDING);
        $this->orderStatusRepo->method('find')->willReturn($pendingStatus);

        $this->configService->method('getConfigData')->willReturn([
            'secret_key' => 'sk_test',
            'capture_on' => true,
            // Legacy value that must be ignored.
            'order_number_format' => 'SHOP-{order_no}',
        ]);

        $komojuPay = new KomojuPay();
        $komojuPay->setName('paypay');

        $repo = $this->createMock(StubRepository::class);
        $repo->method('findOneBy')->willReturn($komojuPay);
        $repo->method('count')->willReturn(1); // one previous attempt -> retry
        $this->entityManager->method('getRepository')->willReturn($repo);
        $this->router->method('generate')->willReturn('https://shop.test/return');

        $client = $this->createMock(KomojuClient::class);
        $client->method('getStatusCode')->willReturn(200);
        $client->expects($this->once())
            ->method('createSession')
            ->with($this->callback(function ($data) {
                // Fixed base preserved, then "-<token>" appended.
                return preg_match('/^ECC-55-[0-9a-f]+$/', $data['payment_data']['external_order_num']) === 1;
            }))
            ->willReturn(['id' => 'ses_1', 'session_url' => 'https://komoju.com/s/1']);
        $this->clientFactory->method('create')->willReturn($client);

        $this->payment->apply();
    }
}
