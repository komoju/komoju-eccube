<?php

namespace Tests\Komoju42\Controller;

use Plugin\Komoju42\Controller\WebhookController;
use Plugin\Komoju42\Service\ConfigService;
use Plugin\Komoju42\Service\LogService;
use Plugin\Komoju42\Service\WebhookService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the public-facing webhook endpoint.
 *
 * The controller's job is small but critical:
 *   1. Verify the HMAC signature on the request body.
 *   2. Dispatch the decoded event to the right WebhookService method.
 *   3. Return JSON status codes that KOMOJU's retry logic can interpret
 *      (200 on success, 4xx for bad input, 5xx for transient failures).
 *
 * We use a Testable subclass to intercept json() so we don't need a full
 * Symfony kernel (JsonResponse is a thin stub from tests/Stubs).
 */
class WebhookControllerTest extends TestCase
{
    private $logService;
    private $configService;
    private $webhookService;
    private $controller;
    private $secret = 'test_webhook_secret';

    protected function setUp(): void
    {
        $this->logService = $this->createMock(LogService::class);
        $this->configService = $this->createMock(ConfigService::class);
        $this->webhookService = $this->createMock(WebhookService::class);

        // The controller calls getConfigData() with no arguments to fetch
        // the webhook secret. Return it once in setUp() for the happy path;
        // failure-path tests override as needed.
        $this->configService->method('getConfigData')->willReturn([
            'webhook_secret' => $this->secret,
        ]);

        $this->controller = new WebhookController(
            $this->logService,
            $this->configService,
            $this->webhookService
        );
    }

    /**
     * Build a Request whose body is signed correctly so the controller's
     * verification passes. $body must already be JSON-encoded.
     */
    private function signedRequest(string $body): Request
    {
        $sig = hash_hmac('sha256', $body, $this->secret);
        return new Request([], ['X-Komoju-Signature' => $sig], $body);
    }

    // --- signature verification ---

    public function testRejectsMissingSignatureWith400()
    {
        $req = new Request([], [], '{"type":"payment.captured","data":{"id":"pay_1"}}');

        $this->webhookService->expects($this->never())->method($this->anything());
        $this->logService->expects($this->once())->method('writeLog')
            ->with('webhook', '', $this->stringContains('verification failed'));

        $resp = $this->controller->webhook($req);

        $this->assertInstanceOf(JsonResponse::class, $resp);
        $this->assertSame(400, $resp->getStatusCode());
        $this->assertSame(['status' => 'error'], $resp->getData());
    }

    public function testRejectsBadSignatureWith400()
    {
        $req = new Request([], ['X-Komoju-Signature' => 'bad'], '{"type":"payment.captured","data":{"id":"x"}}');

        $this->webhookService->expects($this->never())->method($this->anything());
        $this->logService->expects($this->once())->method('writeLog');

        $resp = $this->controller->webhook($req);
        $this->assertSame(400, $resp->getStatusCode());
    }

    public function testRejectsMalformedJsonWith400()
    {
        $body = 'not json at all';
        $req = $this->signedRequest($body);

        $this->webhookService->expects($this->never())->method($this->anything());
        $this->logService->expects($this->once())->method('writeLog');

        $resp = $this->controller->webhook($req);
        $this->assertSame(400, $resp->getStatusCode());
    }

    // --- dispatch ---

    public function testDispatchesPaymentCaptured()
    {
        $body = '{"type":"payment.captured","data":{"id":"pay_cap_1"}}';
        $this->webhookService->expects($this->once())
            ->method('paymentCaptured')
            ->with($this->callback(function ($evt) {
                return $evt->type === 'payment.captured' && $evt->data->id === 'pay_cap_1';
            }));

        $resp = $this->controller->webhook($this->signedRequest($body));
        $this->assertSame(200, $resp->getStatusCode());
        $this->assertSame(['status' => 'success'], $resp->getData());
    }

    public function testDispatchesPaymentAuthorized()
    {
        $body = '{"type":"payment.authorized","data":{"id":"pay_auth_1"}}';
        $this->webhookService->expects($this->once())->method('paymentAuthorized');

        $resp = $this->controller->webhook($this->signedRequest($body));
        $this->assertSame(200, $resp->getStatusCode());
    }

    public function testDispatchesPaymentRefunded()
    {
        $body = '{"type":"payment.refunded","data":{"id":"pay_ref_1","refunds":[]}}';
        $this->webhookService->expects($this->once())->method('paymentRefunded');

        $resp = $this->controller->webhook($this->signedRequest($body));
        $this->assertSame(200, $resp->getStatusCode());
    }

    /**
     * KOMOJU emits both `payment.refunded` and `payment.refund.created` for the
     * same logical event. Both must reach paymentRefunded() so the CAS dedupe
     * in WebhookService can resolve them as duplicates.
     */
    public function testDispatchesPaymentRefundCreatedToSameHandler()
    {
        $body = '{"type":"payment.refund.created","data":{"id":"pay_ref_2","refunds":[]}}';
        $this->webhookService->expects($this->once())->method('paymentRefunded');

        $resp = $this->controller->webhook($this->signedRequest($body));
        $this->assertSame(200, $resp->getStatusCode());
    }

    public function testDispatchesPaymentCancelled()
    {
        $body = '{"type":"payment.cancelled","data":{"id":"pay_can_1"}}';
        $this->webhookService->expects($this->once())->method('paymentCanceled');

        $resp = $this->controller->webhook($this->signedRequest($body));
        $this->assertSame(200, $resp->getStatusCode());
    }

    public function testDispatchesPaymentExpired()
    {
        $body = '{"type":"payment.expired","data":{"id":"pay_exp_1"}}';
        $this->webhookService->expects($this->once())->method('paymentExpired');

        $resp = $this->controller->webhook($this->signedRequest($body));
        $this->assertSame(200, $resp->getStatusCode());
    }

    public function testDispatchesPaymentFailed()
    {
        $body = '{"type":"payment.failed","data":{"id":"pay_fail_1"}}';
        $this->webhookService->expects($this->once())->method('paymentFailed');

        $resp = $this->controller->webhook($this->signedRequest($body));
        $this->assertSame(200, $resp->getStatusCode());
    }

    public function testDispatchesPaymentUpdated()
    {
        $body = '{"type":"payment.updated","data":{"id":"pay_upd_1","status":"expired"}}';
        $this->webhookService->expects($this->once())->method('paymentUpdated');

        $resp = $this->controller->webhook($this->signedRequest($body));
        $this->assertSame(200, $resp->getStatusCode());
    }

    /**
     * Unknown event types must not crash. The endpoint is hit by KOMOJU for
     * every event type the account is subscribed to, including types we
     * haven't implemented yet. Returning success keeps the retry storm at bay.
     */
    public function testIgnoresUnknownEventType()
    {
        $body = '{"type":"payment.something.new","data":{"id":"pay_x"}}';
        $this->webhookService->expects($this->never())->method($this->anything());

        $resp = $this->controller->webhook($this->signedRequest($body));
        $this->assertSame(200, $resp->getStatusCode());
    }

    // --- error handling during dispatch ---

    /**
     * If WebhookService throws during processing the controller MUST return
     * 500 (so KOMOJU retries) and log the failure with the event type tag.
     * Without this, a temporary DB outage would silently 200 and KOMOJU would
     * never re-deliver the event.
     */
    public function testReturns500AndLogsWhenServiceThrows()
    {
        $body = '{"type":"payment.captured","data":{"id":"pay_boom"}}';
        $this->webhookService->method('paymentCaptured')
            ->willThrowException(new \RuntimeException('db is down'));

        $this->logService->expects($this->once())
            ->method('writeLog')
            ->with(
                'webhook[payment.captured]',
                '',
                $this->stringContains('processing failed: db is down')
            );

        $resp = $this->controller->webhook($this->signedRequest($body));
        $this->assertSame(500, $resp->getStatusCode());
        $this->assertSame(
            ['status' => 'error', 'message' => 'processing failed'],
            $resp->getData()
        );
    }
}
