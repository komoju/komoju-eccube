<?php

namespace Tests\Komoju42\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Eccube\Entity\Master\OrderStatus;
use Eccube\Entity\Order;
use Eccube\Service\CartService;
use Eccube\Service\PurchaseFlow\PurchaseFlow;
use Plugin\Komoju42\Controller\SessionReturnController;
use Plugin\Komoju42\Entity\KomojuOrder;
use Plugin\Komoju42\KomojuClient;
use Plugin\Komoju42\Service\ConfigService;
use Plugin\Komoju42\Service\KomojuClientFactory;
use Plugin\Komoju42\Service\LogService;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the customer-facing return URL after KOMOJU redirects back.
 *
 * This is the entry point on which orders flip from PENDING -> PAID (captured
 * payments) or PENDING -> NEW (authorized-only payments like konbini). It has
 * subtle race conditions with the webhook \u2014 the webhook may finalize the
 * order before the customer's browser comes back, in which case this
 * controller must NOT re-process. The test suite below pins these branches:
 *
 *   - Missing / invalid session_id \u2192 flash error, redirect to /shopping.
 *   - KOMOJU API failure \u2192 flash error, redirect to /shopping.
 *   - Payment failed (session not completed, payment not auth/captured) \u2192
 *     rollback purchase flow, set order to PROCESSING, flash + redirect.
 *   - Webhook already finalized \u2192 short-circuit to shopping_complete.\n *   - Happy path (captured) \u2192 set Order PAID, set paymentDate, clear cart.\n *   - Happy path (authorized) \u2192 set Order NEW (so konbini orders surface in\n *     the admin order list before payment is captured).\n *   - flush() throws because the EntityManager was closed by a webhook race \u2192\n *     must NOT crash; must still clear the cart and redirect.\n *\n * The controller depends on Symfony's AbstractController, so we use a\n * Testable subclass that overrides addFlash and redirectToRoute to capture\n * the calls without booting Symfony.\n */
class SessionReturnControllerTest extends TestCase
{
    private $em;
    private $configService;
    private $logService;
    private $purchaseFlow;
    private $requestStack;
    private $session;
    private $cartService;
    private $clientFactory;
    private $komojuClient;
    private $komojuOrderRepo;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->configService = $this->createMock(ConfigService::class);
        $this->logService = $this->createMock(LogService::class);
        $this->purchaseFlow = $this->createMock(PurchaseFlow::class);
        $this->session = new Session();
        $this->requestStack = new RequestStack(new Request(), $this->session);
        $this->cartService = $this->createMock(CartService::class);
        $this->clientFactory = $this->createMock(KomojuClientFactory::class);
        $this->komojuClient = $this->createMock(KomojuClient::class);
        $this->komojuOrderRepo = $this->createMock(\Tests\Komoju42\Service\StubRepository::class);

        $this->configService->method('getConfigData')
            ->willReturn(['secret_key' => 'sk_test']);
        $this->clientFactory->method('create')->willReturn($this->komojuClient);

        // Default em->find returns whatever status the test arranged below.
        // Default em->getRepository returns the komoju_order repo for any
        // entity class; individual tests override for specific classes.
        $this->em->method('getRepository')->willReturnCallback(function ($class) {
            return $this->komojuOrderRepo;
        });
    }

    private function makeController(): TestableSessionReturnController
    {
        return new TestableSessionReturnController(
            $this->em,
            $this->configService,
            $this->logService,
            $this->purchaseFlow,
            $this->requestStack,
            $this->cartService,
            $this->clientFactory
        );
    }

    private function arrangeOrderWithStatus(int $statusId): array
    {
        $status = new OrderStatus();
        $status->setId($statusId);

        $order = new Order();
        $order->setId(42);
        $order->setOrderStatus($status);

        $komojuOrder = new KomojuOrder();
        $komojuOrder->setOrder($order);
        $komojuOrder->setKomojuSessionId('sess_abc');

        $this->komojuOrderRepo->method('findOneBy')->willReturn($komojuOrder);

        return [$order, $komojuOrder, $status];
    }

    // --- early-exit branches ---

    public function testEmptySessionIdRedirectsToShoppingAndLogs()
    {
        $controller = $this->makeController();

        $this->logService->expects($this->once())
            ->method('writeLog')
            ->with('sessionReturn', 0, $this->stringContains('no session_id'));

        $req = new Request([] /* no session_id */);
        $resp = $controller->sessionReturn($req);

        $this->assertSame('shopping', $controller->lastRedirect);
        $this->assertContains('eccube.front.shopping.error', $controller->flashes['types']);
        $this->assertInstanceOf(RedirectResponse::class, $resp);
    }

    public function testUnknownSessionIdRedirectsToShoppingAndLogs()
    {
        $this->komojuOrderRepo->method('findOneBy')->willReturn(null);

        $controller = $this->makeController();

        $this->logService->expects($this->once())
            ->method('writeLog')
            ->with('sessionReturn', 0, $this->stringContains('no komoju_order'));

        $req = new Request(['session_id' => 'sess_unknown']);
        $resp = $controller->sessionReturn($req);

        $this->assertSame('shopping', $controller->lastRedirect);
    }

    public function testKomojuOrderWithoutEcCubeOrderRedirects()
    {
        $komojuOrder = new KomojuOrder();
        $komojuOrder->setKomojuSessionId('sess_orphan');
        // No Order attached.
        $this->komojuOrderRepo->method('findOneBy')->willReturn($komojuOrder);

        $controller = $this->makeController();

        $this->logService->expects($this->once())
            ->method('writeLog')
            ->with('sessionReturn', 0, $this->stringContains('no EC-CUBE order'));

        $req = new Request(['session_id' => 'sess_orphan']);
        $controller->sessionReturn($req);

        $this->assertSame('shopping', $controller->lastRedirect);
    }

    public function testKomojuApiFailureRedirects()
    {
        $this->arrangeOrderWithStatus(OrderStatus::PENDING);

        $this->komojuClient->method('getSession')->willReturn(null);
        $this->komojuClient->method('getStatusCode')->willReturn(500);

        $controller = $this->makeController();

        $this->logService->expects($this->once())
            ->method('writeLog')
            ->with('sessionReturn', 42, $this->stringContains('failed to fetch session'));

        $req = new Request(['session_id' => 'sess_abc']);
        $controller->sessionReturn($req);

        $this->assertSame('shopping', $controller->lastRedirect);
    }

    // --- failed payment branch ---

    public function testFailedPaymentRollsBackAndRedirects()
    {
        [$order, $komojuOrder, $status] = $this->arrangeOrderWithStatus(OrderStatus::PENDING);

        $this->komojuClient->method('getSession')->willReturn([
            'status' => 'failed',
            'payment' => ['status' => 'failed'],
        ]);
        $this->komojuClient->method('getStatusCode')->willReturn(200);

        $processingStatus = new OrderStatus();
        $processingStatus->setId(OrderStatus::PROCESSING);
        $this->em->method('find')->willReturn($processingStatus);

        $this->purchaseFlow->expects($this->once())
            ->method('rollback')
            ->with($order, $this->anything());

        $controller = $this->makeController();
        $req = new Request(['session_id' => 'sess_abc']);
        $controller->sessionReturn($req);

        $this->assertSame('shopping', $controller->lastRedirect);
        $this->assertSame($processingStatus, $order->getOrderStatus(),
            'failed payment must roll the order back to PROCESSING');
    }

    // --- webhook-already-finalized short-circuit ---

    public function testWebhookAlreadyFinalizedSkipsReprocessing()
    {
        // Order is already PAID when the customer's browser comes back.
        [$order, $komojuOrder, $status] = $this->arrangeOrderWithStatus(OrderStatus::PAID);

        $this->komojuClient->method('getSession')->willReturn([
            'status' => 'completed',
            'payment' => ['status' => 'captured', 'id' => 'pay_xyz'],
        ]);
        $this->komojuClient->method('getStatusCode')->willReturn(200);

        // refresh() must be called (we always trust the DB after the API
        // result has been validated) and after it, the controller must NOT
        // run purchase_flow->commit because the webhook already did.
        $this->em->expects($this->once())->method('refresh')->with($order);
        $this->purchaseFlow->expects($this->never())->method('commit');

        // Cart must still be cleared and the order id stored in the session
        // so /shopping_complete renders the receipt.
        $this->cartService->expects($this->once())->method('clear');

        $controller = $this->makeController();
        $req = new Request(['session_id' => 'sess_abc']);
        $resp = $controller->sessionReturn($req);

        $this->assertSame('shopping_complete', $controller->lastRedirect);
        $this->assertSame(42, $this->session->get('eccube.front.shopping.order.id'));
        $this->assertInstanceOf(RedirectResponse::class, $resp);
    }

    // --- happy paths ---

    public function testCapturedPaymentAdvancesOrderToPaid()
    {
        [$order, $komojuOrder, $status] = $this->arrangeOrderWithStatus(OrderStatus::PENDING);

        $this->komojuClient->method('getSession')->willReturn([
            'status' => 'completed',
            'payment' => [
                'id' => 'pay_cap_1',
                'status' => 'captured',
                'payment_details' => ['type' => 'credit_card'],
            ],
        ]);
        $this->komojuClient->method('getStatusCode')->willReturn(200);

        $paidStatus = new OrderStatus();
        $paidStatus->setId(OrderStatus::PAID);
        $this->em->method('find')->willReturn($paidStatus);
        $this->em->method('isOpen')->willReturn(true);

        $this->purchaseFlow->expects($this->once())->method('commit');

        $controller = $this->makeController();
        $req = new Request(['session_id' => 'sess_abc']);
        $resp = $controller->sessionReturn($req);

        $this->assertSame('shopping_complete', $controller->lastRedirect);
        $this->assertSame($paidStatus, $order->getOrderStatus());
        $this->assertNotNull($order->getPaymentDate(),
            'captured payment must stamp paymentDate');
        $this->assertSame('pay_cap_1', $komojuOrder->getKomojuPaymentId());
        $this->assertSame('credit_card', $komojuOrder->getType());
        $this->assertTrue($komojuOrder->isCaptured(),
            'captured payment must set capturedAt on KomojuOrder');
    }

    public function testAuthorizedPaymentAdvancesOrderToNew()
    {
        // Konbini-style flow: session is completed, payment is authorized
        // (awaiting payment at the konbini) \u2014 the order goes to NEW so it
        // shows up in the admin list before money arrives.
        [$order, $komojuOrder, $status] = $this->arrangeOrderWithStatus(OrderStatus::PENDING);

        $this->komojuClient->method('getSession')->willReturn([
            'status' => 'completed',
            'payment' => [
                'id' => 'pay_auth_1',
                'status' => 'authorized',
                'payment_details' => ['type' => 'konbini'],
            ],
        ]);
        $this->komojuClient->method('getStatusCode')->willReturn(200);

        $newStatus = new OrderStatus();
        $newStatus->setId(OrderStatus::NEW);
        $this->em->method('find')->willReturn($newStatus);
        $this->em->method('isOpen')->willReturn(true);

        $controller = $this->makeController();
        $req = new Request(['session_id' => 'sess_abc']);
        $resp = $controller->sessionReturn($req);

        $this->assertSame('shopping_complete', $controller->lastRedirect);
        $this->assertSame($newStatus, $order->getOrderStatus());
        $this->assertNull($order->getPaymentDate(),
            'authorized-only payments must not stamp paymentDate');
        $this->assertFalse($komojuOrder->isCaptured(),
            'authorized-only payments must not mark KomojuOrder as captured');
    }

    // --- crash-safety ---

    /**
     * EntityManager getting closed mid-flow (e.g. the webhook completed first
     * and the second flush blew up) must not crash the customer's return URL.
     * The controller must catch and still redirect to shopping_complete with
     * the order id stored in the session.
     */
    public function testEntityManagerClosedDuringFlushStillRedirects()
    {
        [$order, $komojuOrder, $status] = $this->arrangeOrderWithStatus(OrderStatus::PENDING);

        $this->komojuClient->method('getSession')->willReturn([
            'status' => 'completed',
            'payment' => ['id' => 'pay_1', 'status' => 'captured'],
        ]);
        $this->komojuClient->method('getStatusCode')->willReturn(200);

        $paidStatus = new OrderStatus();
        $paidStatus->setId(OrderStatus::PAID);
        $this->em->method('find')->willReturn($paidStatus);

        // First call to flush() (inside flushWithRetry) succeeds. The second
        // (after commit + status update) throws. Use a counter so we don't
        // bind to PHPUnit's consecutiveCalls (which is ordering-fragile).
        $this->em->method('isOpen')->willReturn(true);
        $flushCount = 0;
        $this->em->method('flush')->willReturnCallback(function () use (&$flushCount) {
            $flushCount++;
            if ($flushCount >= 2) {
                throw new \RuntimeException('EntityManager is closed');
            }
        });

        $controller = $this->makeController();
        $req = new Request(['session_id' => 'sess_abc']);

        // Must not propagate the exception.
        $resp = $controller->sessionReturn($req);

        $this->assertSame('shopping_complete', $controller->lastRedirect,
            'closed-EM exception during flush must not break the return URL');
        $this->assertSame(42, $this->session->get('eccube.front.shopping.order.id'));
    }

    public function testCartServiceFailureFallsBackToSessionKeyCleanup()
    {
        // The CartService->clear() at the END of sessionReturn() (after the
        // happy-path commit) is wrapped in try/catch: if the EntityManager was
        // closed by a webhook race the cart can't be loaded, so the controller
        // falls back to scrubbing the cart session keys directly. Exercise that
        // fallback path with the normal captured-payment flow.
        [$order, $komojuOrder, $status] = $this->arrangeOrderWithStatus(OrderStatus::PENDING);

        $this->komojuClient->method('getSession')->willReturn([
            'status' => 'completed',
            'payment' => ['id' => 'pay_1', 'status' => 'captured'],
        ]);
        $this->komojuClient->method('getStatusCode')->willReturn(200);

        $paidStatus = new OrderStatus();
        $paidStatus->setId(OrderStatus::PAID);
        $this->em->method('find')->willReturn($paidStatus);
        $this->em->method('isOpen')->willReturn(true);

        // Prime the session with stale cart keys so we can assert they're gone.
        $this->session->set('cart_keys', ['k1', 'k2']);
        $this->session->set('cart_key', 'k1');

        $this->cartService->method('clear')->willThrowException(new \RuntimeException('em closed'));

        $controller = $this->makeController();
        $req = new Request(['session_id' => 'sess_abc']);
        $controller->sessionReturn($req);

        $this->assertNull($this->session->get('cart_keys'),
            'cart_keys session entry must be cleared when CartService::clear fails');
        $this->assertNull($this->session->get('cart_key'),
            'cart_key session entry must be cleared when CartService::clear fails');
    }

    // --- sessionCancel ---

    public function testSessionCancelRollsBackOrderAndRedirects()
    {
        [$order, $komojuOrder, $status] = $this->arrangeOrderWithStatus(OrderStatus::PENDING);

        $processing = new OrderStatus();
        $processing->setId(OrderStatus::PROCESSING);
        $this->em->method('find')->willReturn($processing);

        $this->purchaseFlow->expects($this->once())->method('rollback')->with($order);
        $this->logService->expects($this->once())
            ->method('writeLog')
            ->with('sessionCancel', 42, $this->stringContains('cancelled'));

        $controller = $this->makeController();
        $req = new Request(['session_id' => 'sess_abc']);
        $resp = $controller->sessionCancel($req);

        $this->assertSame('shopping', $controller->lastRedirect);
        $this->assertSame($processing, $order->getOrderStatus());
    }

    public function testSessionCancelWithoutSessionIdStillRedirects()
    {
        $this->purchaseFlow->expects($this->never())->method('rollback');

        $controller = $this->makeController();
        $resp = $controller->sessionCancel(new Request([]));

        $this->assertSame('shopping', $controller->lastRedirect);
    }
}

/**
 * Testable subclass that captures addFlash / redirectToRoute side effects.
 * The plugin's controller extends Eccube\Controller\AbstractController whose
 * stub provides these as plain no-ops; we override them here to record calls.
 */
class TestableSessionReturnController extends SessionReturnController
{
    /** @var string|null route name passed to the last redirectToRoute call */
    public $lastRedirect = null;

    /** @var array{types: string[], messages: string[]} */
    public $flashes = ['types' => [], 'messages' => []];

    public function addFlash($type, $message)
    {
        $this->flashes['types'][] = $type;
        $this->flashes['messages'][] = $message;
    }

    public function redirectToRoute($route, array $parameters = [], $status = 302)
    {
        $this->lastRedirect = $route;
        return new RedirectResponse('/route/' . $route);
    }
}
