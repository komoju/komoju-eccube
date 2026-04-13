<?php

namespace Tests\Komoju42\Entity;

use Plugin\Komoju42\Entity\KomojuOrder;
use PHPUnit\Framework\TestCase;

class KomojuOrderTest extends TestCase
{
    public function testIsCapturedDefault()
    {
        $order = new KomojuOrder();
        $this->assertFalse($order->isCaptured());
    }

    public function testIsCapturedTrue()
    {
        $order = new KomojuOrder();
        $order->setCapturedAt(new \DateTime());
        $this->assertTrue($order->isCaptured());
    }

    public function testIsChargeRefundedDefault()
    {
        $order = new KomojuOrder();
        $this->assertFalse($order->getIsChargeRefunded());
    }

    public function testIsChargeRefundedTrue()
    {
        $order = new KomojuOrder();
        $order->setRefundId('ref_123');
        $this->assertTrue($order->getIsChargeRefunded());
    }

    public function testIsCreditType()
    {
        $order = new KomojuOrder();

        $order->setType('credit_card');
        $this->assertTrue($order->isCreditType());

        $order->setType('konbini');
        $this->assertFalse($order->isCreditType());
    }
}
