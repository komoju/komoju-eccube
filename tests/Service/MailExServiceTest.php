<?php

namespace Tests\Komoju42\Service;

use Doctrine\ORM\EntityManagerInterface;
use Eccube\Entity\Order;
use PHPUnit\Framework\TestCase;
use Plugin\Komoju42\Service\MailExService;

class MailExServiceTest extends TestCase
{
    private $entityManager;
    private $service;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->service = $this->getMockBuilder(MailExService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['sendOrderMail'])
            ->getMock();
        $property = new \ReflectionProperty(MailExService::class, 'em');
        $property->setAccessible(true);
        $property->setValue($this->service, $this->entityManager);
    }

    public function testSendsOrderMailBeforeFlushingItsHistory(): void
    {
        $order = new Order();
        $sent = false;
        $this->service->expects($this->once())->method('sendOrderMail')->with($order)
            ->willReturnCallback(function () use (&$sent) {
                $sent = true;
            });
        $this->entityManager->expects($this->once())->method('flush')
            ->willReturnCallback(function () use (&$sent) {
                $this->assertTrue($sent);
            });

        $this->service->sendOrderConfirmationMail($order);
    }

    public function testMailFailureDoesNotEscapeIntoPaymentFinalization(): void
    {
        $this->service->expects($this->once())->method('sendOrderMail')
            ->willThrowException(new \RuntimeException('Template rendering failed'));
        $this->entityManager->expects($this->never())->method('flush');

        $this->service->sendOrderConfirmationMail(new Order());
    }
}
