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

    /**
     * Regression: Symfony's HeaderBag::get() returns null when the header is
     * absent. Passing null straight to hash_equals() / strlen() raises a
     * TypeError in PHP 8+, which is an \Error (not \Exception) and therefore
     * escapes WebhookController's try/catch — leading to a 500 instead of a
     * clean 400. The library must defend against this.
     */
    public function testNullSignatureReturnsFalseInsteadOfThrowing()
    {
        $this->assertFalse(
            WebhookSignature::verifyHeader($this->payload, null, $this->secret),
            'a null signature must be rejected cleanly, not raise TypeError'
        );
    }
}
