<?php

namespace Tests\Komoju42\Service;

use Plugin\Komoju42\Entity\KomojuOrder;
use Plugin\Komoju42\Service\ConfigService;
use Plugin\Komoju42\Service\KomojuClientFactory;
use Plugin\Komoju42\Service\KomojuService;
use Plugin\Komoju42\KomojuClient;
use Eccube\Entity\Order;
use PHPUnit\Framework\TestCase;

class KomojuServiceTest extends TestCase
{
    private $entityManager;
    private $configService;
    private $clientFactory;
    private $komojuOrderRepo;
    private $service;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);
        $this->configService = $this->createMock(ConfigService::class);
        $this->clientFactory = $this->createMock(KomojuClientFactory::class);
        $this->komojuOrderRepo = $this->createMock(StubRepository::class);

        $this->entityManager->method('getRepository')->willReturn($this->komojuOrderRepo);

        $this->service = new KomojuService(
            $this->entityManager,
            $this->configService,
            $this->clientFactory
        );
    }

    public function testCancelNoKomojuOrder()
    {
        $this->komojuOrderRepo->method('findOneBy')->willReturn(null);
        $this->clientFactory->expects($this->never())->method('create');

        $this->service->cancelKomojuOrderByOrder(new Order());
    }

    public function testCancelAlreadyCaptured()
    {
        $komojuOrder = new KomojuOrder();
        $komojuOrder->setCapturedAt(new \DateTime());

        $this->komojuOrderRepo->method('findOneBy')->willReturn($komojuOrder);
        $this->clientFactory->expects($this->never())->method('create');

        $this->service->cancelKomojuOrderByOrder(new Order());
    }

    public function testCancelAlreadyCanceled()
    {
        $komojuOrder = new KomojuOrder();
        $komojuOrder->setCanceledAt(new \DateTime());

        $this->komojuOrderRepo->method('findOneBy')->willReturn($komojuOrder);
        $this->clientFactory->expects($this->never())->method('create');

        $this->service->cancelKomojuOrderByOrder(new Order());
    }

    public function testCancelApiFailure()
    {
        $komojuOrder = new KomojuOrder();
        $komojuOrder->setKomojuPaymentId('pay_1');

        $this->komojuOrderRepo->method('findOneBy')->willReturn($komojuOrder);
        $this->configService->method('getConfigData')->willReturn(['secret_key' => 'sk_test']);

        $client = $this->createMock(KomojuClient::class);
        $client->method('getPayment')->willReturn(null);
        $client->method('getStatusCode')->willReturn(500);

        $this->clientFactory->method('create')->willReturn($client);
        $client->expects($this->never())->method('cancelPayment');

        $this->service->cancelKomojuOrderByOrder(new Order());
    }

    public function testCancelPending()
    {
        $komojuOrder = new KomojuOrder();
        $komojuOrder->setKomojuPaymentId('pay_1');

        $this->komojuOrderRepo->method('findOneBy')->willReturn($komojuOrder);
        $this->configService->method('getConfigData')->willReturn(['secret_key' => 'sk_test']);

        $client = $this->createMock(KomojuClient::class);
        $client->method('getPayment')->willReturn(['status' => 'pending']);
        $client->method('getStatusCode')->willReturn(200);
        $client->expects($this->once())->method('cancelPayment')->with('pay_1');

        $this->clientFactory->method('create')->willReturn($client);

        $this->service->cancelKomojuOrderByOrder(new Order());

        $this->assertNotNull($komojuOrder->getCanceledAt());
    }

    public function testCancelAuthorized()
    {
        $komojuOrder = new KomojuOrder();
        $komojuOrder->setKomojuPaymentId('pay_2');

        $this->komojuOrderRepo->method('findOneBy')->willReturn($komojuOrder);
        $this->configService->method('getConfigData')->willReturn(['secret_key' => 'sk_test']);

        $client = $this->createMock(KomojuClient::class);
        $client->method('getPayment')->willReturn(['status' => 'authorized']);
        $client->method('getStatusCode')->willReturn(200);
        $client->expects($this->once())->method('cancelPayment')->with('pay_2');

        $this->clientFactory->method('create')->willReturn($client);

        $this->service->cancelKomojuOrderByOrder(new Order());

        $this->assertNotNull($komojuOrder->getCanceledAt());
    }

    public function testCancelCapturedStatus()
    {
        $komojuOrder = new KomojuOrder();
        $komojuOrder->setKomojuPaymentId('pay_3');

        $this->komojuOrderRepo->method('findOneBy')->willReturn($komojuOrder);
        $this->configService->method('getConfigData')->willReturn(['secret_key' => 'sk_test']);

        $client = $this->createMock(KomojuClient::class);
        $client->method('getPayment')->willReturn(['status' => 'captured']);
        $client->method('getStatusCode')->willReturn(200);
        $client->expects($this->never())->method('cancelPayment');

        $this->clientFactory->method('create')->willReturn($client);

        $this->service->cancelKomojuOrderByOrder(new Order());

        $this->assertNull($komojuOrder->getCanceledAt());
    }
}
