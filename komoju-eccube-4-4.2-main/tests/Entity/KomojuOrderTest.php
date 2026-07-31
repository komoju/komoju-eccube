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

    /**
     * refund_id is persisted as a string and compared as one by the webhook's
     * compare-and-swap dedupe, so serialisation must not depend on the order
     * KOMOJU happened to return the refunds in.
     */
    public function testCanonicalRefundIdsIsOrderIndependent()
    {
        $newestFirst = ['9jsspntxrw3o353xyv696rv62', '3trzne58odzuwdjw644qgo73w'];
        $oldestFirst = ['3trzne58odzuwdjw644qgo73w', '9jsspntxrw3o353xyv696rv62'];

        $this->assertSame(
            KomojuOrder::canonicalRefundIds($newestFirst),
            KomojuOrder::canonicalRefundIds($oldestFirst),
            'payload ordering must not change the stored refund_id'
        );
        $this->assertSame(
            '3trzne58odzuwdjw644qgo73w,9jsspntxrw3o353xyv696rv62',
            KomojuOrder::canonicalRefundIds($newestFirst)
        );
    }

    public function testCanonicalRefundIdsDropsDuplicatesAndBlanks()
    {
        $this->assertSame(
            'ref_a,ref_b',
            KomojuOrder::canonicalRefundIds(['ref_b', 'ref_a', 'ref_b', '', null])
        );
    }

    public function testCanonicalRefundIdsHandlesEmptySet()
    {
        $this->assertSame('', KomojuOrder::canonicalRefundIds([]));
    }

}
