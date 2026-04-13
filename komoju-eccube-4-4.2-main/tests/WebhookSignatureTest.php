<?php

namespace Tests\Komoju42;

use Komoju\WebhookSignature;
use PHPUnit\Framework\TestCase;

class WebhookSignatureTest extends TestCase
{
    private $secret = 'test_webhook_secret_123';
    private $payload = '{"type":"payment.captured","data":{"id":"pay_abc"}}';

    public function testValidSignature()
    {
        $signature = hash_hmac('sha256', $this->payload, $this->secret);
        $this->assertTrue(WebhookSignature::verifyHeader($this->payload, $signature, $this->secret));
    }

    public function testInvalidSignature()
    {
        $this->assertFalse(WebhookSignature::verifyHeader($this->payload, 'bad_signature', $this->secret));
    }

    public function testWrongSecret()
    {
        $signature = hash_hmac('sha256', $this->payload, $this->secret);
        $this->assertFalse(WebhookSignature::verifyHeader($this->payload, $signature, 'wrong_secret'));
    }

    public function testEmptyPayload()
    {
        $emptyPayload = '';
        $signature = hash_hmac('sha256', $emptyPayload, $this->secret);
        $this->assertTrue(WebhookSignature::verifyHeader($emptyPayload, $signature, $this->secret));
    }
}
