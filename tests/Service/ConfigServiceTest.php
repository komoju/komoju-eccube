<?php

namespace Tests\Komoju42\Service;

use Plugin\Komoju42\Entity\KomojuConfig;
use Plugin\Komoju42\Entity\KomojuPay;
use Plugin\Komoju42\Service\ConfigService;
use Plugin\Komoju42\Service\KomojuClientFactory;
use Plugin\Komoju42\Service\Method\KomojuPayment;
use Plugin\Komoju42\KomojuClient;
use Eccube\Common\EccubeConfig;
use Eccube\Entity\Payment;
use Eccube\Entity\PaymentOption;
use PHPUnit\Framework\TestCase;

class ConfigServiceTest extends TestCase
{
    private $entityManager;
    private $eccubeConfig;
    private $clientFactory;
    private $service;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);
        $this->eccubeConfig = $this->createMock(EccubeConfig::class);
        $this->clientFactory = $this->createMock(KomojuClientFactory::class);
        $this->service = new ConfigService($this->entityManager, $this->eccubeConfig, $this->clientFactory);
    }

    // --- syncPaymentMethods ---

    public function testSyncEmptyKeyReturnsFalse()
    {
        $this->assertFalse($this->service->syncPaymentMethods(''));
    }

    public function testSyncNullKeyReturnsFalse()
    {
        $this->assertFalse($this->service->syncPaymentMethods(null));
    }

    public function testSyncApiFailureReturnsFalse()
    {
        $client = $this->createMock(KomojuClient::class);
        $client->method('getPaymentMethods')->willReturn(null);
        $client->method('getStatusCode')->willReturn(500);

        $this->clientFactory->method('create')->willReturn($client);

        $this->assertFalse($this->service->syncPaymentMethods('sk_test'));
    }

    public function testSyncApiNon200ReturnsFalse()
    {
        $client = $this->createMock(KomojuClient::class);
        $client->method('getPaymentMethods')->willReturn(['data' => []]);
        $client->method('getStatusCode')->willReturn(401);

        $this->clientFactory->method('create')->willReturn($client);

        $this->assertFalse($this->service->syncPaymentMethods('sk_bad'));
    }

    public function testSyncApiEmptyResponseReturnsFalse()
    {
        $client = $this->createMock(KomojuClient::class);
        $client->method('getPaymentMethods')->willReturn([]);
        $client->method('getStatusCode')->willReturn(200);

        $this->clientFactory->method('create')->willReturn($client);

        $this->assertFalse($this->service->syncPaymentMethods('sk_test'));
    }

    public function testSyncApiNonArrayDataReturnsFalse()
    {
        $client = $this->createMock(KomojuClient::class);
        $client->method('getPaymentMethods')->willReturn(['data' => 'not_array']);
        $client->method('getStatusCode')->willReturn(200);

        $this->clientFactory->method('create')->willReturn($client);

        $this->assertFalse($this->service->syncPaymentMethods('sk_test'));
    }

    // --- getConfigData ---

    public function testGetConfigDataDelegatesToRepo()
    {
        $expectedConfig = ['secret_key' => 'sk_123', 'publishable_key' => 'pk_123'];

        $configRepo = $this->createMock(StubConfigRepository::class);
        $configRepo->method('getConfigByOrder')->willReturn($expectedConfig);

        $this->entityManager->method('getRepository')
            ->with(KomojuConfig::class)
            ->willReturn($configRepo);

        $result = $this->service->getConfigData(null);
        $this->assertEquals($expectedConfig, $result);
    }

    public function testGetConfigDataPassesOrder()
    {
        $order = new \Eccube\Entity\Order();
        $order->setId(42);

        $configRepo = $this->createMock(StubConfigRepository::class);
        $configRepo->expects($this->once())
            ->method('getConfigByOrder')
            ->with($order)
            ->willReturn(['secret_key' => 'sk_for_order']);

        $this->entityManager->method('getRepository')
            ->with(KomojuConfig::class)
            ->willReturn($configRepo);

        $result = $this->service->getConfigData($order);
        $this->assertEquals('sk_for_order', $result['secret_key']);
    }

    // --- hasPaymentMethods ---

    public function testHasPaymentMethodsTrue()
    {
        $pay = new KomojuPay();
        $repo = $this->createMock(StubRepository::class);
        $repo->method('findBy')->willReturn([$pay]);

        $this->entityManager->method('getRepository')
            ->with(KomojuPay::class)
            ->willReturn($repo);

        $this->assertTrue($this->service->hasPaymentMethods());
    }

    public function testHasPaymentMethodsFalse()
    {
        $repo = $this->createMock(StubRepository::class);
        $repo->method('findBy')->willReturn([]);

        $this->entityManager->method('getRepository')
            ->with(KomojuPay::class)
            ->willReturn($repo);

        $this->assertFalse($this->service->hasPaymentMethods());
    }

    // --- disablePlugin ---

    public function testDisablePluginHidesAllPayments()
    {
        $payment1 = new Payment();
        $payment1->setVisible(true);
        $payment2 = new Payment();
        $payment2->setVisible(true);

        $paymentRepo = $this->createMock(StubRepository::class);
        $paymentRepo->method('findBy')
            ->with(['method_class' => KomojuPayment::class])
            ->willReturn([$payment1, $payment2]);

        $this->entityManager->method('getRepository')
            ->with(Payment::class)
            ->willReturn($paymentRepo);

        $this->entityManager->expects($this->exactly(2))->method('persist');
        $this->entityManager->expects($this->once())->method('flush');

        $this->service->disablePlugin();

        $this->assertFalse($payment1->getVisible());
        $this->assertFalse($payment2->getVisible());
    }

    public function testDisablePluginNoPayments()
    {
        $paymentRepo = $this->createMock(StubRepository::class);
        $paymentRepo->method('findBy')->willReturn([]);

        $this->entityManager->method('getRepository')
            ->with(Payment::class)
            ->willReturn($paymentRepo);

        $this->entityManager->expects($this->never())->method('persist');

        $this->service->disablePlugin();
    }

    public function testRetireReferencedPaymentHidesInsteadOfDeleting()
    {
        $payment = new Payment();
        $payment->setId(267);
        $payment->setVisible(true);
        $options = $this->createMock(StubConfigRepository::class);
        $options->method('findBy')->willReturn([]);
        $connection = $this->createMock(\Doctrine\DBAL\Connection::class);
        $connection->expects($this->once())->method('fetchOne')
            ->with('SELECT COUNT(*) FROM dtb_order WHERE payment_id = ?', [267])
            ->willReturn(5);
        $this->entityManager->method('getConnection')->willReturn($connection);
        $this->entityManager->method('getRepository')->with(PaymentOption::class)->willReturn($options);
        $this->entityManager->expects($this->once())->method('persist')->with($payment);
        $this->entityManager->expects($this->never())->method('remove');

        (new TestableConfigService($this->entityManager, $this->eccubeConfig, $this->clientFactory))
            ->retire($payment);

        $this->assertFalse($payment->getVisible());
    }

    public function testRetireUnreferencedPaymentDeletesIt()
    {
        $payment = new Payment();
        $payment->setId(268);
        $options = $this->createMock(StubConfigRepository::class);
        $options->method('findBy')->willReturn([]);
        $connection = $this->createMock(\Doctrine\DBAL\Connection::class);
        $connection->method('fetchOne')->willReturn(0);
        $this->entityManager->method('getConnection')->willReturn($connection);
        $this->entityManager->method('getRepository')->with(PaymentOption::class)->willReturn($options);
        $this->entityManager->expects($this->never())->method('persist');
        $this->entityManager->expects($this->once())->method('remove')->with($payment);

        (new TestableConfigService($this->entityManager, $this->eccubeConfig, $this->clientFactory))
            ->retire($payment);
    }
}

class TestableConfigService extends ConfigService
{
    public function retire(Payment $Payment)
    {
        $this->retirePayment($Payment);
    }
}

/**
 * @internal Stub for mocking repository methods
 */
class StubConfigRepository
{
    public function getConfigByOrder($order = null) { return []; }
    public function get() { return null; }
    public function findOneBy(array $criteria) { return null; }
    public function findBy(array $criteria, ?array $orderBy = null) { return []; }
}
