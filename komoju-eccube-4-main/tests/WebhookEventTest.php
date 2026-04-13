<?php

namespace Tests\Komoju;

use Komoju\WebhookEvent;
use PHPUnit\Framework\TestCase;

class WebhookEventTest extends TestCase
{
    private $secret = 'test_secret';

    public function testValidEvent()
    {
        $payload = json_encode(['type' => 'payment.captured', 'data' => ['id' => 'pay_123']]);
        $signature = hash_hmac('sha256', $payload, $this->secret);

        $result = WebhookEvent::constructEvent($payload, $signature, $this->secret);

        $this->assertEquals('payment.captured', $result->type);
        $this->assertEquals('pay_123', $result->data->id);
    }

    public function testInvalidSignature()
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('verify_error');

        $payload = json_encode(['type' => 'payment.captured']);
        WebhookEvent::constructEvent($payload, 'bad_sig', $this->secret);
    }

    public function testMalformedJson()
    {
        $payload = '{invalid json!!!';
        $signature = hash_hmac('sha256', $payload, $this->secret);

        $this->expectException(\UnexpectedValueException::class);
        WebhookEvent::constructEvent($payload, $signature, $this->secret);
    }
}
