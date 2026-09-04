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
    private const CALLBACK_STATE = 'callback_state_abc';


    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $connection = $this->createMock(\Doctrine\DBAL\Connection::class);
        $connection->method('executeStatement')->willReturn(1);
        $this->em->method('getConnection')->willReturn($connection);
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
    private function callbackRequest(): Request
    {
        return new Request([
            'session_id' => 'sess_abc',
            'state' => self::CALLBACK_STATE,
        ]);
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
        $stateHash = hash('sha256', self::CALLBACK_STATE);
        $komojuOrder->setCallbackTokenHash($stateHash);
        $this->session->set('komoju.callback.sess_abc', $stateHash);

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

    public function testMissingCallbackStateIsRejected()
    {
        $this->arrangeOrderWithStatus(OrderStatus::PENDING);
        $this->komojuClient->expects($this->never())->method('getSession');

        $controller = $this->makeController();
        $controller->sessionReturn(new Request(['session_id' => 'sess_abc']));

        $this->assertSame('shopping', $controller->lastRedirect);
        $this->assertNull($this->session->get('eccube.front.shopping.order.id'));
    }

    public function testInvalidCallbackStateIsRejected()
    {
        $this->arrangeOrderWithStatus(OrderStatus::PENDING);
        $this->komojuClient->expects($this->never())->method('getSession');

        $controller = $this->makeController();
        $controller->sessionReturn(new Request([
            'session_id' => 'sess_abc',
            'state' => 'forged',
        ]));

        $this->assertSame('shopping', $controller->lastRedirect);
    }

    public function testMissingBrowserBindingDoesNotExposeCompletion()
    {
        [$order] = $this->arrangeOrderWithStatus(OrderStatus::PAID);
        $this->session->remove('komoju.callback.sess_abc');
        $this->komojuClient->method('getSession')->willReturn([
            'status' => 'completed',
            'payment' => ['id' => 'pay_1', 'status' => 'captured'],
        ]);
        $this->komojuClient->method('getStatusCode')->willReturn(200);
        $this->em->expects($this->once())->method('refresh')->with($order);

        $controller = $this->makeController();
        $controller->sessionReturn($this->callbackRequest());

        $this->assertSame('shopping', $controller->lastRedirect);
        $this->assertNull($this->session->get('eccube.front.shopping.order.id'));
    }

    public function testSessionAmountMismatchIsRejected()
    {
        [$order, $komojuOrder] = $this->arrangeOrderWithStatus(OrderStatus::PENDING);
        $komojuOrder->setExpectedAmount(1000);
        $komojuOrder->setExpectedCurrency('JPY');
        $this->komojuClient->method('getSession')->willReturn([
            'id' => 'sess_abc',
            'status' => 'completed',
            'amount' => 1,
            'currency' => 'JPY',
            'payment' => ['id' => 'pay_1', 'status' => 'captured'],
        ]);
        $this->komojuClient->method('getStatusCode')->willReturn(200);
        $this->purchaseFlow->expects($this->never())->method('commit');
        $this->purchaseFlow->expects($this->never())->method('rollback');

        $controller = $this->makeController();
        $controller->sessionReturn($this->callbackRequest());

        $this->assertSame('shopping', $controller->lastRedirect);
        $this->assertSame(OrderStatus::PENDING, $order->getOrderStatus()->getId());
    }

    public function testMissingConfigRedirectsWithoutCustomer500()
    {
        [$order] = $this->arrangeOrderWithStatus(OrderStatus::PENDING);
        $this->configService->method('getConfigData')
            ->willThrowException(new \RuntimeException('missing config'));
        $this->em->expects($this->once())->method('refresh')->with($order);
        $this->purchaseFlow->expects($this->never())->method('rollback');

        $controller = $this->makeController();
        $controller->sessionReturn($this->callbackRequest());

        $this->assertSame('shopping', $controller->lastRedirect);
        $this->assertContains('eccube.front.shopping.error', $controller->flashes['types']);
    }

    /**
     * A config row that exists but has no secret key cannot be used to reach
     * KOMOJU, and must be handled like any other config failure rather than
     * passing null into the client factory.
     */
    public function testEmptySecretKeyIsTreatedAsConfigFailure()
    {
        [$order] = $this->arrangeOrderWithStatus(OrderStatus::PENDING);

        $configService = $this->createMock(ConfigService::class);
        $configService->method('getConfigData')->willReturn(['secret_key' => '']);
        $this->configService = $configService;

        $this->clientFactory->expects($this->never())->method('create');
        $this->purchaseFlow->expects($this->never())->method('rollback');

        $controller = $this->makeController();
        $controller->sessionReturn($this->callbackRequest());

        $this->assertSame('shopping', $controller->lastRedirect);
    }

    /**
     * Config failure AFTER the webhook already finalized the order must still
     * take the customer to the receipt, not back to checkout to pay twice.
     */
    public function testConfigFailureAfterWebhookFinalizedGoesToComplete()
    {
        [$order] = $this->arrangeOrderWithStatus(OrderStatus::PAID);

        $configService = $this->createMock(ConfigService::class);
        $configService->method('getConfigData')
            ->willThrowException(new \RuntimeException('missing config'));
        $this->configService = $configService;

        $this->purchaseFlow->expects($this->never())->method('rollback');
        $this->cartService->expects($this->once())->method('clear');

        $controller = $this->makeController();
        $controller->sessionReturn($this->callbackRequest());

        $this->assertSame('shopping_complete', $controller->lastRedirect);
        $this->assertSame(42, $this->session->get('eccube.front.shopping.order.id'));
    }

    public function testKomojuApiFailureRedirects()
    {
        [$order, , $status] = $this->arrangeOrderWithStatus(OrderStatus::PENDING);

        $this->komojuClient->method('getSession')->willReturn(null);
        $this->komojuClient->method('getStatusCode')->willReturn(500);

        // A transient API failure is INDETERMINATE: the customer may have paid.
        // The controller must NOT roll back (that would restore stock a paid
        // order still owns -> oversell). It leaves the order PENDING for the
        // authoritative webhook to finalize and shows a "confirming payment"
        // message.
        $this->purchaseFlow->expects($this->never())->method('rollback');

        $controller = $this->makeController();
        $req = $this->callbackRequest();
        $controller->sessionReturn($req);

        $this->assertSame('shopping', $controller->lastRedirect);
        $this->assertSame($status, $order->getOrderStatus(),
            'API failure must leave the order in its PENDING status for the webhook');
    }

    public function testApiFailureAfterWebhookFinalizedGoesToComplete()
    {
        // Customer paid; webhook already set the order PAID; then getSession()
        // has a transient failure. The customer must land on completion, not be
        // sent back to re-pay.
        [$order] = $this->arrangeOrderWithStatus(OrderStatus::PAID);

        $this->komojuClient->method('getSession')->willReturn(null);
        $this->komojuClient->method('getStatusCode')->willReturn(503);

        // Must NOT roll back an already-finalized order.
        $this->purchaseFlow->expects($this->never())->method('rollback');

        $controller = $this->makeController();
        $req = $this->callbackRequest();
        $controller->sessionReturn($req);

        $this->assertSame('shopping_complete', $controller->lastRedirect);
    }

    // --- failed payment branch ---

    /**
     * Terminal failures are the ONLY case where rolling back is safe: KOMOJU has
     * told us definitively that no money was taken.
     *
     * @dataProvider terminalFailureProvider
     */
    public function testTerminalFailureRollsBackStock($sessionStatus, $paymentStatus)
    {
        [$order] = $this->arrangeOrderWithStatus(OrderStatus::PENDING);
        $this->komojuClient->method('getSession')->willReturn([
            'status' => $sessionStatus,
            'payment' => ['status' => $paymentStatus],
        ]);
        $this->komojuClient->method('getStatusCode')->willReturn(200);
        $processing = new OrderStatus();
        $processing->setId(OrderStatus::PROCESSING);
        $this->em->method('find')->willReturn($processing);

        $this->purchaseFlow->expects($this->once())->method('rollback');

        $controller = $this->makeController();
        $controller->sessionReturn($this->callbackRequest());

        $this->assertSame('shopping', $controller->lastRedirect);
        $this->assertSame($processing, $order->getOrderStatus());
    }

    public function terminalFailureProvider(): array
    {
        return [
            'payment failed' => ['failed', 'failed'],
            'payment cancelled' => ['completed', 'cancelled'],
            'payment expired' => ['completed', 'expired'],
            'session cancelled' => ['cancelled', 'pending'],
            'session failed' => ['failed', 'pending'],
        ];
    }

    public function testTerminalReturnDoesNotRollbackAuthorizedOrder()
    {
        [$order] = $this->arrangeOrderWithStatus(OrderStatus::NEW);
        $this->komojuClient->method('getSession')->willReturn([
            'status' => 'cancelled',
            'payment' => ['status' => 'cancelled'],
        ]);
        $this->komojuClient->method('getStatusCode')->willReturn(200);
        $this->purchaseFlow->expects($this->never())->method('rollback');

        $controller = $this->makeController();
        $controller->sessionReturn($this->callbackRequest());

        $this->assertSame('shopping', $controller->lastRedirect);
        $this->assertSame(OrderStatus::NEW, $order->getOrderStatus()->getId());
    }

    public function testConcurrentCaptureBlocksTerminalRollback()
    {
        [$order, $attempt] = $this->arrangeOrderWithStatus(OrderStatus::PENDING);
        $id = new \ReflectionProperty(KomojuOrder::class, 'id');
        if(PHP_VERSION_ID < 80100){
            $id->setAccessible(true);
        }
        $id->setValue($attempt, 13);

        $this->komojuClient->method('getSession')->willReturn([
            'status' => 'cancelled',
            'payment' => ['status' => 'cancelled'],
        ]);
        $this->komojuClient->method('getStatusCode')->willReturn(200);

        $paid = new OrderStatus();
        $paid->setId(OrderStatus::PAID);
        $connection = $this->createMock(\Doctrine\DBAL\Connection::class);
        $connection->method('executeStatement')->willReturn(0);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);
        $em->method('getRepository')->willReturn($this->komojuOrderRepo);
        $em->method('refresh')->willReturnCallback(function ($entity) use ($paid) {
            $entity->setOrderStatus($paid);
        });
        $this->em = $em;
        $this->purchaseFlow->expects($this->never())->method('rollback');

        $controller = $this->makeController();
        $controller->sessionReturn($this->callbackRequest());

        $this->assertSame('shopping_complete', $controller->lastRedirect);
        $this->assertSame(OrderStatus::PAID, $order->getOrderStatus()->getId());
    }

    /**
     * A captured or authorized payment means KOMOJU may already hold the
     * customer's money. Rolling back would restore stock the paid order still
     * owns, so these must finalize regardless of the session envelope status
     * (which can lag, expire, or be missing on a late/retried return).
     *
     * @dataProvider moneyTakenProvider
     */
    public function testMoneyTakenIsNeverRolledBack($sessionStatus, $paymentStatus)
    {
        [$order, $komojuOrder] = $this->arrangeOrderWithStatus(OrderStatus::PENDING);
        $this->komojuClient->method('getSession')->willReturn([
            'status' => $sessionStatus,
            'payment' => ['id' => 'pay_real', 'status' => $paymentStatus, 'amount' => 4080],
        ]);
        $this->komojuClient->method('getStatusCode')->willReturn(200);
        $this->em->method('isOpen')->willReturn(true);
        $finalStatus = new OrderStatus();
        $finalStatus->setId($paymentStatus === 'captured' ? OrderStatus::PAID : OrderStatus::NEW);
        $this->em->method('find')->willReturn($finalStatus);

        $this->purchaseFlow->expects($this->never())->method('rollback');

        $controller = $this->makeController();
        $controller->sessionReturn($this->callbackRequest());

        $this->assertSame('shopping_complete', $controller->lastRedirect);
        $this->assertSame('pay_real', $komojuOrder->getKomojuPaymentId());
    }

    public function moneyTakenProvider(): array
    {
        return [
            'completed + captured' => ['completed', 'captured'],
            'completed + authorized' => ['completed', 'authorized'],
            'lagging session + captured' => ['pending', 'captured'],
            'missing session status + captured' => ['unknown', 'captured'],
            'expired session + captured' => ['expired', 'captured'],
            'cancelled session + authorized' => ['cancelled', 'authorized'],
        ];
    }

    /**
     * Indeterminate outcomes (konbini awaiting funding, missing payment block)
     * must neither finalize nor roll back: the webhook is authoritative.
     *
     * @dataProvider indeterminateProvider
     */
    public function testIndeterminatePaymentIsLeftForWebhook($sessionStatus, $payment)
    {
        [$order, , $status] = $this->arrangeOrderWithStatus(OrderStatus::PENDING);
        $this->komojuClient->method('getSession')->willReturn([
            'status' => $sessionStatus,
            'payment' => $payment,
        ]);
        $this->komojuClient->method('getStatusCode')->willReturn(200);

        $this->purchaseFlow->expects($this->never())->method('rollback');
        $this->purchaseFlow->expects($this->never())->method('commit');

        $controller = $this->makeController();
        $controller->sessionReturn($this->callbackRequest());

        $this->assertSame('shopping', $controller->lastRedirect);
        $this->assertSame($status, $order->getOrderStatus(),
            'an indeterminate outcome must leave the order status untouched for the webhook');
    }

    public function indeterminateProvider(): array
    {
        return [
            'konbini awaiting funding' => ['completed', ['status' => 'pending']],
            'missing payment block' => ['completed', []],
            'unknown payment status' => ['completed', ['status' => 'weird_new_status']],
            'session still pending' => ['pending', ['status' => 'pending']],
        ];
    }

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
        $req = $this->callbackRequest();
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
        $req = $this->callbackRequest();
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
        $resp = $controller->sessionReturn($this->callbackRequest());

        $this->assertSame('shopping_complete', $controller->lastRedirect);
        $this->assertSame($paidStatus, $order->getOrderStatus());
        $this->assertNotNull($order->getPaymentDate());
        $this->assertSame('pay_cap_1', $komojuOrder->getKomojuPaymentId());
        $this->assertSame('credit_card', $komojuOrder->getType());
        $this->assertTrue($komojuOrder->isCaptured());
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
        $refreshCount = 0;
        $this->em->method('refresh')->willReturnCallback(
            function ($entity) use (&$refreshCount, $newStatus) {
                if($entity instanceof Order){
                    $refreshCount++;
                    if($refreshCount >= 2){
                        $entity->setOrderStatus($newStatus);
                    }
                }
            }
        );

        $controller = $this->makeController();
        $req = $this->callbackRequest();
        $resp = $controller->sessionReturn($req);

        $this->assertSame('shopping_complete', $controller->lastRedirect);
        $this->assertSame($newStatus, $order->getOrderStatus());
        $this->assertNull($order->getPaymentDate(),
            'authorized-only payments must not stamp paymentDate');
        $this->assertFalse($komojuOrder->isCaptured(),
            'authorized-only payments must not mark KomojuOrder as captured');
    }

    // --- crash-safety ---

    public function testEntityManagerFailureShowsPendingInsteadOfCompletion()
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

        // The second flush simulates a concurrent persistence failure.
        $flushCount = 0;
        $this->em->method('flush')->willReturnCallback(function () use (&$flushCount) {
            $flushCount++;
            throw new \RuntimeException('EntityManager is closed');
        });
        // The entity manager closes after the failed flush.
        $this->em->method('isOpen')->willReturnCallback(function () use (&$flushCount) {
            return $flushCount < 2;
        });

        $controller = $this->makeController();
        $req = $this->callbackRequest();

        $resp = $controller->sessionReturn($req);

        $this->assertSame('shopping', $controller->lastRedirect);
        $this->assertNull($this->session->get('eccube.front.shopping.order.id'));
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
        $req = $this->callbackRequest();
        $controller->sessionReturn($req);

        $this->assertNull($this->session->get('cart_keys'),
            'cart_keys session entry must be cleared when CartService::clear fails');
        $this->assertNull($this->session->get('cart_key'),
            'cart_key session entry must be cleared when CartService::clear fails');
    }

    // --- sessionCancel ---

    public function testSessionCancelUsesVerifiedRemoteStatus()
    {
        [$order] = $this->arrangeOrderWithStatus(OrderStatus::PENDING);
        $this->komojuClient->method('getSession')->willReturn([
            'status' => 'cancelled',
            'payment' => ['status' => 'pending'],
        ]);
        $this->komojuClient->method('getStatusCode')->willReturn(200);

        $processing = new OrderStatus();
        $processing->setId(OrderStatus::PROCESSING);
        $this->em->method('find')->willReturn($processing);
        $this->purchaseFlow->expects($this->once())->method('rollback')->with($order);

        $controller = $this->makeController();
        $controller->sessionCancel($this->callbackRequest());

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

    public function testSessionCancelDoesNotRollBackAlreadyPaidOrder()
    {
        [$order] = $this->arrangeOrderWithStatus(OrderStatus::PAID);
        $this->komojuClient->method('getSession')->willReturn([
            'status' => 'completed',
            'payment' => ['id' => 'pay_1', 'status' => 'captured'],
        ]);
        $this->komojuClient->method('getStatusCode')->willReturn(200);
        $this->purchaseFlow->expects($this->never())->method('rollback');

        $controller = $this->makeController();
        $controller->sessionCancel($this->callbackRequest());

        $this->assertSame('shopping_complete', $controller->lastRedirect);
        $this->assertSame(OrderStatus::PAID, $order->getOrderStatus()->getId());
    }

    public function testFinalizeFailureDoesNotRollbackTakenMoney()
    {
        [$order] = $this->arrangeOrderWithStatus(OrderStatus::PENDING);

        $this->komojuClient->method('getSession')->willReturn([
            'status' => 'completed',
            'payment' => ['id' => 'pay_1', 'status' => 'captured'],
        ]);
        $this->komojuClient->method('getStatusCode')->willReturn(200);

        $processingStatus = new OrderStatus();
        $processingStatus->setId(OrderStatus::PROCESSING);
        $this->em->method('find')->willReturn($processingStatus);
        $this->em->method('isOpen')->willReturn(true);

        $this->purchaseFlow->method('commit')
            ->willThrowException(new \RuntimeException('over stock'));
        $this->purchaseFlow->expects($this->never())->method('rollback');

        $controller = $this->makeController();
        $req = $this->callbackRequest();
        $controller->sessionReturn($req);

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
