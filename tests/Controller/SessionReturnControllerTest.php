<?php

namespace Tests\Komoju42\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Eccube\Entity\Master\OrderStatus;
use Eccube\Entity\Order;
use Eccube\Entity\Cart;
use Eccube\Service\CartService;
use Eccube\Service\MailService;
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

/** Browser returns must preserve reserved carts until a payment outcome is known. */
class SessionReturnControllerTest extends TestCase
{
    private $em;
    private $configService;
    private $logService;
    private $purchaseFlow;
    private $requestStack;
    private $session;
    private $cartService;
    private $checkoutCart;
    private $clientFactory;
    private $komojuClient;
    private $mailService;
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
        $this->cartService->method('getCart')->willReturnCallback(function () {
            return $this->checkoutCart;
        });
        $this->clientFactory = $this->createMock(KomojuClientFactory::class);
        $this->komojuClient = $this->createMock(KomojuClient::class);
        $this->mailService = $this->createMock(MailService::class);
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
            $this->clientFactory,
            $this->mailService
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
        $order->setPreOrderId('checkout-42');

        $komojuOrder = new KomojuOrder();
        $komojuOrder->setOrder($order);
        $komojuOrder->setKomojuSessionId('sess_abc');
        $stateHash = hash('sha256', self::CALLBACK_STATE);
        $komojuOrder->setCallbackTokenHash($stateHash);
        $this->session->set('komoju.callback.sess_abc', $stateHash);
        $this->checkoutCart = $this->makeCart(84, 'checkout-42');
        $this->session->set('komoju.callback_cart.sess_abc', [
            'order_id' => 42,
            'cart_id' => 84,
            'pre_order_id' => 'checkout-42',
        ]);

        $this->komojuOrderRepo->method('findOneBy')->willReturn($komojuOrder);

        return [$order, $komojuOrder, $status];
    }

    private function makeCart($id, $preOrderId): Cart
    {
        $cart = new Cart();
        $property = new \ReflectionProperty(Cart::class, 'id');
        if (PHP_VERSION_ID < 80100) {
            $property->setAccessible(true);
        }
        $property->setValue($cart, $id);
        $cart->setPreOrderId($preOrderId)->setCartKey('reusable-cart-key');
        return $cart;
    }

    private function arrangeCompletedCart(): CheckoutCartService
    {
        $this->arrangeOrderWithStatus(OrderStatus::PAID);
        $this->komojuClient->method('getSession')->willReturn([
            'status' => 'completed',
            'payment' => ['id' => 'pay_1', 'status' => 'captured'],
        ]);
        $this->komojuClient->method('getStatusCode')->willReturn(200);
        $cartService = new CheckoutCartService([$this->checkoutCart], $this->checkoutCart);
        $this->cartService = $cartService;
        return $cartService;
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
        $this->assertContains('eccube.front.error', $controller->flashes['types']);
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
        $this->mailService->expects($this->never())->method('sendOrderMail');

        $controller = $this->makeController();
        $controller->sessionReturn(new Request(['session_id' => 'sess_abc']));

        $this->assertSame('payment_pending', $controller->lastRedirect);
        $this->assertNull($this->session->get('eccube.front.shopping.order.id'));
    }

    public function testInvalidCallbackStateIsRejected()
    {
        $this->arrangeOrderWithStatus(OrderStatus::PENDING);
        $this->komojuClient->expects($this->never())->method('getSession');
        $this->mailService->expects($this->never())->method('sendOrderMail');

        $controller = $this->makeController();
        $controller->sessionReturn(new Request([
            'session_id' => 'sess_abc',
            'state' => 'forged',
        ]));

        $this->assertSame('payment_pending', $controller->lastRedirect);
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
        $this->mailService->expects($this->never())->method('sendOrderMail');

        $controller = $this->makeController();
        $controller->sessionReturn($this->callbackRequest());

        $this->assertSame('payment_pending', $controller->lastRedirect);
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
        $this->mailService->expects($this->never())->method('sendOrderMail');

        $controller = $this->makeController();
        $controller->sessionReturn($this->callbackRequest());

        $this->assertSame('payment_pending', $controller->lastRedirect);
        $this->assertSame(OrderStatus::PENDING, $order->getOrderStatus()->getId());
    }

    public function testSessionPaymentMismatchIsRejected()
    {
        [$order, $komojuOrder] = $this->arrangeOrderWithStatus(OrderStatus::PENDING);
        $komojuOrder->setKomojuPaymentId('pay_success');
        $this->komojuClient->method('getSession')->willReturn([
            'id' => 'sess_abc',
            'status' => 'completed',
            'payment' => ['id' => 'pay_old', 'status' => 'captured'],
        ]);
        $this->komojuClient->method('getStatusCode')->willReturn(200);
        $this->purchaseFlow->expects($this->never())->method('commit');
        $this->purchaseFlow->expects($this->never())->method('rollback');
        $this->mailService->expects($this->never())->method('sendOrderMail');

        $controller = $this->makeController();
        $controller->sessionReturn($this->callbackRequest());

        $this->assertSame('payment_pending', $controller->lastRedirect);
        $this->assertSame(OrderStatus::PENDING, $order->getOrderStatus()->getId());
    }

    /** @dataProvider rejectedCompletionProvider */
    public function testRejectedOrderStatusNeverExposesCompletion($statusId, $lookup)
    {
        [$order] = $this->arrangeOrderWithStatus($statusId);
        if ($lookup === 'config_error') {
            $this->configService = $this->createMock(ConfigService::class);
            $this->configService->method('getConfigData')
                ->willThrowException(new \RuntimeException('config unavailable'));
        } else {
            $this->komojuClient->method('getSession')->willReturn($lookup === 'api_error' ? null : [
                'status' => 'completed',
                'payment' => ['id' => 'pay_1', 'status' => 'captured'],
            ]);
            $this->komojuClient->method('getStatusCode')->willReturn($lookup === 'api_error' ? 503 : 200);
        }
        $this->cartService->expects($this->never())->method('clear');
        $this->purchaseFlow->expects($this->never())->method('commit');
        $this->purchaseFlow->expects($this->never())->method('rollback');
        $this->mailService->expects($this->never())->method('sendOrderMail');

        $controller = $this->makeController();
        $controller->sessionReturn($this->callbackRequest());

        $this->assertSame('payment_pending', $controller->lastRedirect);
        $this->assertNull($this->session->get('eccube.front.shopping.order.id'));
        $this->assertSame($statusId, $order->getOrderStatus()->getId());
    }

    public function rejectedCompletionProvider(): array
    {
        return [
            'canceled order during API outage' => [OrderStatus::CANCEL, 'api_error'],
            'canceled order with missing config' => [OrderStatus::CANCEL, 'config_error'],
            'returned order with captured payment' => [OrderStatus::RETURNED, 'captured'],
            'unknown order status' => [999, 'captured'],
        ];
    }

    /** @dataProvider acceptedCompletionProvider */
    public function testAcceptedOrderRemainsAccessibleDuringApiFailure($statusId)
    {
        [$order] = $this->arrangeOrderWithStatus($statusId);
        $this->komojuClient->method('getSession')->willReturn(null);
        $this->komojuClient->method('getStatusCode')->willReturn(503);
        $this->purchaseFlow->expects($this->never())->method('commit');
        $this->purchaseFlow->expects($this->never())->method('rollback');
        $this->mailService->expects($this->never())->method('sendOrderMail');

        $controller = $this->makeController();
        $controller->sessionReturn($this->callbackRequest());

        $this->assertSame('shopping_complete', $controller->lastRedirect);
        $this->assertSame(42, $this->session->get('eccube.front.shopping.order.id'));
        $this->assertSame($statusId, $order->getOrderStatus()->getId());
    }

    public function acceptedCompletionProvider(): array
    {
        return [
            'accepted awaiting payment' => [OrderStatus::NEW],
            'being fulfilled' => [OrderStatus::IN_PROGRESS],
            'delivered' => [OrderStatus::DELIVERED],
        ];
    }

    /** @dataProvider lookupFailureProvider */
    public function testRefreshFailureCannotUseStalePaidOrder($configFailure)
    {
        $this->arrangeOrderWithStatus(OrderStatus::PAID);
        if ($configFailure) {
            $this->configService = $this->createMock(ConfigService::class);
            $this->configService->method('getConfigData')
                ->willThrowException(new \RuntimeException('config unavailable'));
        } else {
            $this->komojuClient->method('getSession')->willReturn(null);
            $this->komojuClient->method('getStatusCode')->willReturn(503);
        }
        $this->em->method('refresh')->willThrowException(new \RuntimeException('database unavailable'));
        $this->em->expects($this->never())->method('flush');
        $this->cartService->expects($this->never())->method('clear');
        $this->mailService->expects($this->never())->method('sendOrderMail');

        $controller = $this->makeController();
        $controller->sessionReturn($this->callbackRequest());

        $this->assertSame('payment_pending', $controller->lastRedirect);
        $this->assertNull($this->session->get('eccube.front.shopping.order.id'));
    }

    public function lookupFailureProvider(): array
    {
        return ['API unavailable' => [false], 'configuration unavailable' => [true]];
    }

    /** @dataProvider reconciliationStatusProvider */
    public function testRefreshFailureBeforePaymentMutationIsContained($paymentStatus)
    {
        [$order, $attempt] = $this->arrangeOrderWithStatus(OrderStatus::PENDING);
        $this->komojuClient->method('getSession')->willReturn([
            'status' => 'completed',
            'payment' => ['id' => 'pay_1', 'status' => $paymentStatus],
        ]);
        $this->komojuClient->method('getStatusCode')->willReturn(200);
        $this->em->method('refresh')->willThrowException(new \RuntimeException('database unavailable'));
        $this->purchaseFlow->expects($this->never())->method('commit');
        $this->purchaseFlow->expects($this->never())->method('rollback');
        $this->cartService->expects($this->never())->method('clear');
        $this->mailService->expects($this->never())->method('sendOrderMail');

        $controller = $this->makeController();
        $controller->sessionReturn($this->callbackRequest());

        $this->assertSame('payment_pending', $controller->lastRedirect);
        $this->assertSame(OrderStatus::PENDING, $order->getOrderStatus()->getId());
        $this->assertFalse($attempt->isCaptured());
        $this->assertNull($attempt->getCanceledAt());
        $this->assertNull($this->session->get('eccube.front.shopping.order.id'));
    }

    public function reconciliationStatusProvider(): array
    {
        return ['captured payment' => ['captured'], 'terminal payment' => ['cancelled']];
    }

    /** @dataProvider unresolvedSessionProvider */
    public function testUnresolvedSessionPreservesReservationAndOnlyResumesOpenSessions($session, $resume, $cancel)
    {
        [$order, $attempt] = $this->arrangeOrderWithStatus(OrderStatus::PENDING);
        $this->komojuClient->method('getSession')->willReturn($session);
        $this->komojuClient->method('getStatusCode')->willReturn(200);
        $this->komojuClient->expects($this->never())->method('cancelSession');
        $this->purchaseFlow->expects($this->never())->method('rollback');
        $this->purchaseFlow->expects($this->never())->method('commit');
        $this->cartService->expects($this->never())->method('clear');
        $controller = $this->makeController();

        $response = $controller->sessionStatus(new Request(['session_id' => 'sess_abc']));

        $this->assertSame('payment_pending', $controller->lastRedirect);
        $this->assertSame('sess_abc', $controller->pendingParameters['session_id']);
        $this->assertSame($resume ? 'https://komoju.com/sessions/sess_abc' : null, $controller->pendingParameters['resume_url']);
        $this->assertSame($cancel, $controller->pendingParameters['can_cancel']);
        $this->assertSame('no-store, private', $response->headers->get('Cache-Control'));
        $this->assertSame(OrderStatus::PENDING, $order->getOrderStatus()->getId());
        $this->assertNull($attempt->getCanceledAt());
    }

    public function unresolvedSessionProvider(): array
    {
        return [
            'unsubmitted checkout' => [['status' => 'pending', 'expired' => false, 'payment' => null], true, true],
            'pending payment' => [['status' => 'pending', 'expired' => false, 'payment' => ['status' => 'pending']], true, true],
            'retryable failure' => [['status' => 'pending', 'expired' => false, 'payment' => ['status' => 'failed']], true, true],
            'cancellation still processing' => [['status' => 'cancelled', 'payment' => ['status' => 'pending']], false, false],
            'expired with live payment' => [['status' => 'pending', 'expired' => true, 'payment' => ['status' => 'pending']], false, false],
            'missing payment field' => [['status' => 'cancelled', 'expired' => false], false, false],
            'unknown payment status' => [['status' => 'pending', 'expired' => false, 'payment' => ['status' => 'new_status']], false, true],
        ];
    }

    /**
     * A closed session whose payment is absent or dead can never take money, and
     * KOMOJU sends no webhook for an empty session, so the return releases the
     * reservation itself and reopens the order for checkout.
     *
     * @dataProvider releasableSessionProvider
     */
    public function testClosedSessionWithoutLivePaymentReleasesReservation($session)
    {
        [$order, $attempt] = $this->arrangeOrderWithStatus(OrderStatus::PENDING);
        $this->komojuClient->method('getSession')->willReturn($session);
        $this->komojuClient->method('getStatusCode')->willReturn(200);
        $this->em->method('find')->willReturn((new OrderStatus())->setId(OrderStatus::PROCESSING));
        $this->purchaseFlow->expects($this->once())->method('rollback')->with($order);
        $this->purchaseFlow->expects($this->never())->method('commit');
        $this->cartService->expects($this->never())->method('clear');

        $controller = $this->makeController();
        $controller->sessionReturn($this->callbackRequest());

        $this->assertSame('shopping', $controller->lastRedirect);
        $this->assertSame(['komoju_payment.shopping.payment_cancelled'], $controller->flashes['messages']);
        $this->assertSame(OrderStatus::PROCESSING, $order->getOrderStatus()->getId());
        $this->assertNotNull($attempt->getCanceledAt(), 'late webhooks for this attempt must be inert');
    }

    public function releasableSessionProvider(): array
    {
        return [
            'customer cancelled before paying' => [['status' => 'cancelled', 'payment' => null]],
            'abandoned session expired' => [['status' => 'pending', 'expired' => true, 'payment' => null]],
            'expired after failed attempt' => [['status' => 'pending', 'expired' => true, 'payment' => ['status' => 'failed']]],
            'cancelled after failed attempt' => [['status' => 'cancelled', 'payment' => ['status' => 'failed']]],
            'payment cancelled' => [['status' => 'completed', 'payment' => ['status' => 'cancelled']]],
            'konbini expired' => [['status' => 'completed', 'payment' => ['status' => 'expired']]],
        ];
    }

    public function testConcurrentReleaseRestoresStockOnlyOnce()
    {
        [$order] = $this->arrangeOrderWithStatus(OrderStatus::PENDING);
        $id = new \ReflectionProperty(KomojuOrder::class, 'id');
        if(PHP_VERSION_ID < 80100){
            $id->setAccessible(true);
        }
        $id->setValue($this->komojuOrderRepo->findOneBy([]), 13);
        $this->komojuClient->method('getSession')->willReturn(['status' => 'cancelled', 'payment' => null]);
        $this->komojuClient->method('getStatusCode')->willReturn(200);
        $connection = $this->createMock(\Doctrine\DBAL\Connection::class);
        $connection->method('executeStatement')->willReturn(0); // another request claimed it
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);
        $em->method('getRepository')->willReturn($this->komojuOrderRepo);
        $this->em = $em;
        $this->purchaseFlow->expects($this->never())->method('rollback');

        $controller = $this->makeController();
        $controller->sessionReturn($this->callbackRequest());

        $this->assertSame('payment_pending', $controller->lastRedirect);
        $this->assertSame(OrderStatus::PENDING, $order->getOrderStatus()->getId());
    }

    public function testCustomerCancelClosesSessionThenReleasesReservation()
    {
        [$order, $attempt] = $this->arrangeOrderWithStatus(OrderStatus::PENDING);
        $cancelled = false;
        $this->komojuClient->expects($this->once())->method('cancelSession')->with('sess_abc')
            ->willReturnCallback(function () use (&$cancelled) { $cancelled = true; return []; });
        $this->komojuClient->method('getSession')->willReturnCallback(function () use (&$cancelled) {
            return $cancelled
                ? ['status' => 'cancelled', 'payment' => null]
                : ['status' => 'pending', 'expired' => false, 'payment' => null];
        });
        $this->komojuClient->method('getStatusCode')->willReturn(200);
        $this->em->method('find')->willReturn((new OrderStatus())->setId(OrderStatus::PROCESSING));
        $this->purchaseFlow->expects($this->once())->method('rollback')->with($order);

        $controller = $this->makeController();
        $controller->sessionAbandon(new Request(['session_id' => 'sess_abc'], ['_token' => 't']));

        $this->assertSame('shopping', $controller->lastRedirect);
        $this->assertSame(OrderStatus::PROCESSING, $order->getOrderStatus()->getId());
        $this->assertNotNull($attempt->getCanceledAt());
    }

    public function testCustomerCancelWaitsForWebhookWhilePaymentIsInFlight()
    {
        [$order, $attempt] = $this->arrangeOrderWithStatus(OrderStatus::PENDING);
        $this->komojuClient->expects($this->once())->method('cancelSession');
        $this->komojuClient->method('getSession')->willReturn(['status' => 'cancelled', 'payment' => ['status' => 'pending']]);
        $this->komojuClient->method('getStatusCode')->willReturn(200);
        $this->purchaseFlow->expects($this->never())->method('rollback');

        $controller = $this->makeController();
        $controller->sessionAbandon(new Request(['session_id' => 'sess_abc'], ['_token' => 't']));

        $this->assertSame('payment_pending', $controller->lastRedirect);
        $this->assertFalse($controller->pendingParameters['can_cancel']);
        $this->assertSame(OrderStatus::PENDING, $order->getOrderStatus()->getId());
        $this->assertNull($attempt->getCanceledAt());
    }

    public function testCustomerCancelRequiresOriginalBrowser()
    {
        $this->arrangeOrderWithStatus(OrderStatus::PENDING);
        $this->session->remove('komoju.callback.sess_abc');
        $this->komojuClient->expects($this->never())->method('cancelSession');
        $this->komojuClient->expects($this->never())->method('getSession');
        $this->purchaseFlow->expects($this->never())->method('rollback');

        $controller = $this->makeController();
        $controller->sessionAbandon(new Request(['session_id' => 'sess_abc'], ['_token' => 't']));

        $this->assertSame('payment_pending', $controller->lastRedirect);
    }

    /** KOMOJU's "back" link returns with the session still open and nothing submitted. */
    public function testBackFromKomojuClosesUnsubmittedSessionAndReleasesReservation()
    {
        [$order, $attempt] = $this->arrangeOrderWithStatus(OrderStatus::PENDING);
        $this->komojuClient->method('getSession')->willReturn(['id' => 'sess_abc', 'status' => 'pending', 'expired' => false, 'payment' => null]);
        $this->komojuClient->expects($this->once())->method('cancelSession')->with('sess_abc')
            ->willReturn(['id' => 'sess_abc', 'status' => 'cancelled', 'payment' => null]);
        $this->komojuClient->method('getStatusCode')->willReturn(200);
        $this->em->method('find')->willReturn((new OrderStatus())->setId(OrderStatus::PROCESSING));
        $this->purchaseFlow->expects($this->once())->method('rollback')->with($order);

        $controller = $this->makeController();
        $controller->sessionReturn($this->callbackRequest());

        $this->assertSame('shopping', $controller->lastRedirect);
        $this->assertSame(OrderStatus::PROCESSING, $order->getOrderStatus()->getId());
        $this->assertNotNull($attempt->getCanceledAt());
    }

    public function testBackFromKomojuKeepsReservationWhenCloseIsNotConfirmed()
    {
        [$order, $attempt] = $this->arrangeOrderWithStatus(OrderStatus::PENDING);
        $this->komojuClient->method('getSession')->willReturn(['id' => 'sess_abc', 'status' => 'pending', 'expired' => false, 'payment' => null]);
        $this->komojuClient->method('cancelSession')->willReturn(['id' => 'sess_abc', 'status' => 'completed', 'payment' => ['status' => 'pending']]);
        $this->komojuClient->method('getStatusCode')->willReturn(200);
        $this->purchaseFlow->expects($this->never())->method('rollback');

        $controller = $this->makeController();
        $controller->sessionReturn($this->callbackRequest());

        $this->assertSame('payment_pending', $controller->lastRedirect);
        $this->assertSame(OrderStatus::PENDING, $order->getOrderStatus()->getId());
        $this->assertNull($attempt->getCanceledAt());
    }

    public function testStatusRecoveryRequiresBrowserBindingEvenWithCallbackToken()
    {
        $this->arrangeOrderWithStatus(OrderStatus::PENDING);
        $this->session->remove('komoju.callback.sess_abc');
        $this->komojuClient->expects($this->never())->method('getSession');
        $this->purchaseFlow->expects($this->never())->method('rollback');
        $controller = $this->makeController();
        $controller->sessionStatus($this->callbackRequest());
        $this->assertNull($controller->pendingParameters['session_id']);
        $this->assertNull($controller->pendingParameters['resume_url']);
    }

    public function testStatusRecoveryCompletesCapturedPaymentWithoutCreatingAnotherOrder()
    {
        [$order] = $this->arrangeOrderWithStatus(OrderStatus::PENDING);
        $this->komojuClient->method('getSession')->willReturn([
            'status' => 'completed', 'payment' => ['id' => 'pay_1', 'status' => 'captured'],
        ]);
        $this->komojuClient->method('getStatusCode')->willReturn(200);
        $this->em->method('find')->willReturn((new OrderStatus())->setId(OrderStatus::PAID));
        $this->purchaseFlow->expects($this->once())->method('commit')->with($order);
        $this->purchaseFlow->expects($this->never())->method('rollback');
        $this->cartService->expects($this->once())->method('clear');
        $controller = $this->makeController();
        $controller->sessionStatus(new Request(['session_id' => 'sess_abc']));
        $this->assertSame('shopping_complete', $controller->lastRedirect);
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

        $this->assertSame('payment_pending', $controller->lastRedirect);
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

        $this->assertSame('payment_pending', $controller->lastRedirect);
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
        $this->mailService->expects($this->never())->method('sendOrderMail');

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

        $this->assertSame('payment_pending', $controller->lastRedirect);
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
        $this->mailService->expects($this->never())->method('sendOrderMail');

        $controller = $this->makeController();
        $req = $this->callbackRequest();
        $controller->sessionReturn($req);

        $this->assertSame('shopping_complete', $controller->lastRedirect);
    }

    // --- failed payment branch ---

    /** @dataProvider terminalFailureProvider */
    public function testClosedSessionWithLivePaymentWaitsForPaymentWebhook($sessionStatus, $paymentStatus)
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
        $this->purchaseFlow->expects($this->never())->method('rollback');
        $this->mailService->expects($this->never())->method('sendOrderMail');

        $controller = $this->makeController();
        $controller->sessionReturn($this->callbackRequest());

        $this->assertSame('payment_pending', $controller->lastRedirect);
        $this->assertSame(OrderStatus::PENDING, $order->getOrderStatus()->getId());
    }

    public function terminalFailureProvider(): array
    {
        return [
            'session cancelled, payment cancellation queued' => ['cancelled', 'pending'],
            'konbini awaiting payment' => ['completed', 'pending'],
            'unknown session status' => ['failed', 'failed'],
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
        $this->mailService->expects($this->never())->method('sendOrderMail');

        $controller = $this->makeController();
        $controller->sessionReturn($this->callbackRequest());

        $this->assertSame('shopping_complete', $controller->lastRedirect);
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
        $this->mailService->expects($this->never())->method('sendOrderMail');

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
        $this->purchaseFlow->method('commit')->willReturnCallback(function ($order) use ($finalStatus) {
            $order->setOrderStatus($finalStatus);
        });

        $this->purchaseFlow->expects($this->never())->method('rollback');
        $this->mailService->expects($this->once())->method('sendOrderMail')->with($order);

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
        $this->mailService->expects($this->never())->method('sendOrderMail');

        $controller = $this->makeController();
        $controller->sessionReturn($this->callbackRequest());

        $this->assertSame('payment_pending', $controller->lastRedirect);
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

    public function testFailedPaymentRemainsRetryable()
    {
        [$order, $komojuOrder, $status] = $this->arrangeOrderWithStatus(OrderStatus::PENDING);

        $this->komojuClient->method('getSession')->willReturn([
            'status' => 'pending',
            'payment' => ['status' => 'failed'],
        ]);
        $this->komojuClient->method('getStatusCode')->willReturn(200);
        $this->purchaseFlow->expects($this->never())->method('rollback');
        $this->mailService->expects($this->never())->method('sendOrderMail');

        $controller = $this->makeController();
        $controller->sessionReturn($this->callbackRequest());

        $this->assertSame('payment_pending', $controller->lastRedirect);
        $this->assertSame($status, $order->getOrderStatus());
        $this->assertNull($komojuOrder->getCanceledAt());
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

        $this->purchaseFlow->expects($this->never())->method('commit');
        $this->mailService->expects($this->never())->method('sendOrderMail');

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

    public function testCapturedAcceptedOrderIsReconciledOnlyOnce()
    {
        [$order, $attempt] = $this->arrangeOrderWithStatus(OrderStatus::NEW);
        $orderDate = new \DateTime('2026-09-01T10:00:00Z');
        $capturedAt = new \DateTime('2026-09-02T10:00:00Z');
        $order->setOrderDate($orderDate);
        $this->komojuClient->method('getSession')->willReturn([
            'status' => 'completed',
            'payment' => ['id' => 'pay_1', 'status' => 'captured', 'captured_at' => $capturedAt->format(DATE_ATOM)],
        ]);
        $this->komojuClient->method('getStatusCode')->willReturn(200);
        $this->em->method('find')->willReturn((new OrderStatus())->setId(OrderStatus::PAID));
        $this->purchaseFlow->expects($this->never())->method('commit');
        $this->purchaseFlow->expects($this->never())->method('rollback');
        $this->mailService->expects($this->never())->method('sendOrderMail');

        $controller = $this->makeController();
        $controller->sessionReturn($this->callbackRequest());

        $this->assertSame(OrderStatus::PAID, $order->getOrderStatus()->getId());
        $this->assertEquals($capturedAt, $attempt->getCapturedAt());
        $this->assertEquals($capturedAt, $order->getPaymentDate());
        $this->assertSame($orderDate, $order->getOrderDate());
        $paymentDate = $order->getPaymentDate();

        $controller->sessionReturn($this->callbackRequest());

        $this->assertSame('shopping_complete', $controller->lastRedirect);
        $this->assertSame($paymentDate, $order->getPaymentDate());
        $this->assertSame($orderDate, $order->getOrderDate());
    }

    public function testAcceptedOrderKeepsPreviouslyRecordedCaptureTime()
    {
        [$order, $attempt] = $this->arrangeOrderWithStatus(OrderStatus::NEW);
        $capturedAt = new \DateTime('2026-09-02T10:00:00Z');
        $attempt->setCapturedAt($capturedAt);
        $this->komojuClient->method('getSession')->willReturn([
            'status' => 'completed',
            'payment' => ['id' => 'pay_1', 'status' => 'captured'],
        ]);
        $this->komojuClient->method('getStatusCode')->willReturn(200);
        $this->em->method('find')->willReturn((new OrderStatus())->setId(OrderStatus::PAID));
        $this->purchaseFlow->expects($this->never())->method('commit');
        $this->mailService->expects($this->never())->method('sendOrderMail');

        $controller = $this->makeController();
        $controller->sessionReturn($this->callbackRequest());

        $this->assertSame(OrderStatus::PAID, $order->getOrderStatus()->getId());
        $this->assertEquals($capturedAt, $order->getPaymentDate());
        $this->assertEquals($capturedAt, $attempt->getCapturedAt());
    }

    /** @dataProvider concurrentAcceptedOrderProvider */
    public function testAcceptedOrderRespectsStatusChangedBeforeLock($lockedStatus)
    {
        [$order, $attempt] = $this->arrangeOrderWithStatus(OrderStatus::NEW);
        $capturedAt = new \DateTime('2026-09-02T10:00:00Z');
        $this->komojuClient->method('getSession')->willReturn([
            'status' => 'completed',
            'payment' => ['id' => 'pay_1', 'status' => 'captured'],
        ]);
        $this->komojuClient->method('getStatusCode')->willReturn(200);
        $orderRefreshes = 0;
        $this->em->method('refresh')->willReturnCallback(function ($entity) use (&$orderRefreshes, $lockedStatus, $capturedAt, $attempt) {
            if ($entity instanceof Order && ++$orderRefreshes === 2) {
                $entity->setOrderStatus((new OrderStatus())->setId($lockedStatus));
                if ($lockedStatus === OrderStatus::PAID) {
                    $entity->setPaymentDate($capturedAt);
                    $attempt->setCapturedAt($capturedAt);
                }
            }
        });
        $this->purchaseFlow->expects($this->never())->method('commit');
        $this->purchaseFlow->expects($this->never())->method('rollback');
        $this->mailService->expects($this->never())->method('sendOrderMail');

        $controller = $this->makeController();
        $controller->sessionReturn($this->callbackRequest());

        $this->assertSame($lockedStatus, $order->getOrderStatus()->getId());
        if ($lockedStatus === OrderStatus::PAID) {
            $this->assertSame('shopping_complete', $controller->lastRedirect);
            $this->assertSame($capturedAt, $order->getPaymentDate());
        } else {
            $this->assertSame('payment_pending', $controller->lastRedirect);
            $this->assertFalse($attempt->isCaptured());
            $this->assertNull($this->session->get('eccube.front.shopping.order.id'));
        }
    }

    public function concurrentAcceptedOrderProvider(): array
    {
        return ['canceled concurrently' => [OrderStatus::CANCEL], 'captured concurrently' => [OrderStatus::PAID]];
    }

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
        $this->mailService->expects($this->once())->method('sendOrderMail')->with($order)
            ->willReturnCallback(function ($order) use ($paidStatus) {
                $this->assertSame($paidStatus, $order->getOrderStatus());
                $this->assertNotNull($order->getPaymentDate());
            });

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
        $this->purchaseFlow->expects($this->once())->method('commit')->with($order)
            ->willReturnCallback(function ($order) use ($newStatus) {
                $order->setOrderStatus($newStatus);
            });
        $this->mailService->expects($this->once())->method('sendOrderMail')->with($order)
            ->willReturnCallback(function ($order) use ($newStatus) {
                $this->assertSame($newStatus, $order->getOrderStatus());
                $this->assertNull($order->getPaymentDate());
            });

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

    public function testAuthorizedOrderSendsOneConfirmationAcrossLaterCaptureAndReplay()
    {
        [$order, $attempt] = $this->arrangeOrderWithStatus(OrderStatus::PENDING);
        $authorized = [
            'status' => 'completed',
            'payment' => ['id' => 'pay_1', 'status' => 'authorized'],
        ];
        $captured = [
            'status' => 'completed',
            'payment' => ['id' => 'pay_1', 'status' => 'captured'],
        ];
        $this->komojuClient->method('getSession')->willReturnOnConsecutiveCalls($authorized, $captured, $captured);
        $this->komojuClient->method('getStatusCode')->willReturn(200);
        $this->em->method('find')->willReturn((new OrderStatus())->setId(OrderStatus::PAID));
        $this->purchaseFlow->expects($this->once())->method('commit')->with($order)
            ->willReturnCallback(function ($order) {
                $order->setOrderStatus((new OrderStatus())->setId(OrderStatus::NEW));
            });
        $this->purchaseFlow->expects($this->never())->method('rollback');
        $this->mailService->expects($this->once())->method('sendOrderMail')->with($order)
            ->willReturnCallback(function () use ($order) {
                $this->assertSame(OrderStatus::NEW, $order->getOrderStatus()->getId());
            });

        $controller = $this->makeController();
        $controller->sessionReturn($this->callbackRequest());

        $this->assertSame('shopping_complete', $controller->lastRedirect);
        $this->assertSame(OrderStatus::NEW, $order->getOrderStatus()->getId());
        $this->assertFalse($attempt->isCaptured());

        $controller->sessionReturn($this->callbackRequest());

        $this->assertSame('shopping_complete', $controller->lastRedirect);
        $this->assertSame(OrderStatus::PAID, $order->getOrderStatus()->getId());
        $this->assertTrue($attempt->isCaptured());
        $paymentDate = $order->getPaymentDate();

        $controller->sessionReturn($this->callbackRequest());

        $this->assertSame('shopping_complete', $controller->lastRedirect);
        $this->assertSame($paymentDate, $order->getPaymentDate());
    }

    /** @dataProvider finalizationPaymentProvider */
    public function testLosingFinalizationClaimDoesNotSendConfirmation($paymentStatus)
    {
        [$order] = $this->arrangeOrderWithStatus(OrderStatus::PENDING);
        $this->komojuClient->method('getSession')->willReturn([
            'status' => 'completed',
            'payment' => ['id' => 'pay_1', 'status' => $paymentStatus],
        ]);
        $this->komojuClient->method('getStatusCode')->willReturn(200);
        $finalStatus = (new OrderStatus())->setId($paymentStatus === 'captured' ? OrderStatus::PAID : OrderStatus::NEW);
        $connection = $this->createMock(\Doctrine\DBAL\Connection::class);
        $connection->expects($this->once())->method('executeStatement')->with(
            'UPDATE dtb_order SET order_status_id = ? WHERE id = ? AND order_status_id IN (?, ?)',
            [$finalStatus->getId(), $order->getId(), OrderStatus::PENDING, OrderStatus::PROCESSING]
        )->willReturn(0);
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->em->method('getConnection')->willReturn($connection);
        $this->em->method('getRepository')->willReturn($this->komojuOrderRepo);
        $this->em->method('find')->willReturn($finalStatus);
        $orderRefreshes = 0;
        $this->em->method('refresh')->willReturnCallback(function ($entity) use (&$orderRefreshes, $finalStatus) {
            if ($entity instanceof Order && ++$orderRefreshes === 3) {
                $entity->setOrderStatus($finalStatus);
            }
        });
        $this->purchaseFlow->expects($this->never())->method('commit');
        $this->purchaseFlow->expects($this->never())->method('rollback');
        $this->mailService->expects($this->never())->method('sendOrderMail');

        $controller = $this->makeController();
        $controller->sessionReturn($this->callbackRequest());

        $this->assertSame('shopping_complete', $controller->lastRedirect);
        $this->assertSame($finalStatus, $order->getOrderStatus());
    }

    public function finalizationPaymentProvider(): array
    {
        return ['captured' => ['captured'], 'authorized' => ['authorized']];
    }

    // --- crash-safety ---

    public function testTransactionCommitFailureDoesNotSendConfirmation()
    {
        [$order] = $this->arrangeOrderWithStatus(OrderStatus::PENDING);
        $this->komojuClient->method('getSession')->willReturn([
            'status' => 'completed',
            'payment' => ['id' => 'pay_1', 'status' => 'captured'],
        ]);
        $this->komojuClient->method('getStatusCode')->willReturn(200);
        $this->em->method('find')->willReturn((new OrderStatus())->setId(OrderStatus::PAID));
        $connection = $this->em->getConnection();
        $connection->method('commit')->willThrowException(new \RuntimeException('commit failed'));
        $this->purchaseFlow->expects($this->once())->method('commit')->with($order);
        $this->purchaseFlow->expects($this->never())->method('rollback');
        $this->cartService->expects($this->never())->method('clear');
        $this->mailService->expects($this->never())->method('sendOrderMail');

        $controller = $this->makeController();
        $controller->sessionReturn($this->callbackRequest());

        $this->assertSame('payment_pending', $controller->lastRedirect);
        $this->assertNull($this->session->get('eccube.front.shopping.order.id'));
    }

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
        $this->mailService->expects($this->never())->method('sendOrderMail');

        $controller = $this->makeController();
        $req = $this->callbackRequest();

        $resp = $controller->sessionReturn($req);

        $this->assertSame('payment_pending', $controller->lastRedirect);
        $this->assertNull($this->session->get('eccube.front.shopping.order.id'));
    }


    public function testCompletedCallbackClearsOnlyItsCartAndReplayPreservesNewCart()
    {
        $cartService = $this->arrangeCompletedCart();
        $this->mailService->expects($this->never())->method('sendOrderMail');
        $otherCart = $this->makeCart(85, 'other-checkout');
        $cartService->carts[85] = $otherCart;
        $controller = $this->makeController();

        $controller->sessionReturn($this->callbackRequest());

        $this->assertArrayNotHasKey(84, $cartService->carts);
        $this->assertSame($otherCart, $cartService->carts[85]);
        $this->assertSame('shopping_complete', $controller->lastRedirect);

        $newCart = $this->makeCart(86, 'new-checkout');
        $cartService->carts[86] = $newCart;
        $cartService->current = $newCart;
        $controller->sessionReturn($this->callbackRequest());

        $this->assertSame($newCart, $cartService->carts[86]);
        $this->assertSame($otherCart, $cartService->carts[85]);
        $this->assertSame('shopping_complete', $controller->lastRedirect);
    }

    /** @dataProvider changedCheckoutCartProvider */
    public function testDelayedCallbackCannotClearDifferentCheckout($cartId, $preOrderId)
    {
        $cartService = $this->arrangeCompletedCart();
        $newCart = $this->makeCart($cartId, $preOrderId);
        $cartService->carts = [$cartId => $newCart];
        $cartService->current = $newCart;

        $controller = $this->makeController();
        $controller->sessionReturn($this->callbackRequest());

        $this->assertSame($newCart, $cartService->carts[$cartId]);
        $this->assertSame('shopping_complete', $controller->lastRedirect);
    }

    public function changedCheckoutCartProvider(): array
    {
        return [
            'replacement cart using the same key' => [85, 'checkout-42'],
            'same cart now belongs to a new order' => [84, 'new-checkout'],
        ];
    }

    public function testCartChangedBeforeCleanupLockIsPreserved()
    {
        $cartService = $this->arrangeCompletedCart();
        $cart = $cartService->current;
        $this->em->method('refresh')->willReturnCallback(function ($entity) {
            if ($entity instanceof Cart) {
                $entity->setPreOrderId('new-checkout');
            }
        });

        $controller = $this->makeController();
        $controller->sessionReturn($this->callbackRequest());

        $this->assertSame($cart, $cartService->carts[84]);
        $this->assertSame('shopping_complete', $controller->lastRedirect);
    }

    /** @dataProvider missingCartBindingProvider */
    public function testCallbackWithoutCleanupAuthorityPreservesCart($missingKey, $expectedRoute)
    {
        $cartService = $this->arrangeCompletedCart();
        $cart = $cartService->current;
        $this->session->remove($missingKey);

        $controller = $this->makeController();
        $controller->sessionReturn($this->callbackRequest());

        $this->assertSame($cart, $cartService->carts[84]);
        $this->assertSame($expectedRoute, $controller->lastRedirect);
    }

    public function missingCartBindingProvider(): array
    {
        return [
            'legacy callback without cart identity' => ['komoju.callback_cart.sess_abc', 'shopping_complete'],
            'missing browser binding' => ['komoju.callback.sess_abc', 'payment_pending'],
        ];
    }

    public function testCleanupFailurePreservesCartsAndDoesNotRetryOnNewCart()
    {
        $cartService = $this->arrangeCompletedCart();
        $original = $cartService->current;
        $other = $this->makeCart(85, 'other-checkout');
        $cartService->carts[85] = $other;
        $cartService->failClear = true;
        $this->session->set('cart_keys', ['original', 'other']);
        $this->session->set('cart_key', 'original');
        $controller = $this->makeController();

        $controller->sessionReturn($this->callbackRequest());

        $this->assertSame($original, $cartService->carts[84]);
        $this->assertSame($other, $cartService->carts[85]);
        $this->assertSame(['original', 'other'], $this->session->get('cart_keys'));
        $this->assertSame('original', $this->session->get('cart_key'));
        $this->assertSame('shopping_complete', $controller->lastRedirect);

        $cartService->failClear = false;
        $cartService->current = $other;
        $controller->sessionReturn($this->callbackRequest());

        $this->assertSame($other, $cartService->carts[85]);
        $this->assertSame('shopping_complete', $controller->lastRedirect);
    }

    // --- sessionCancel ---

    public function testCancelledSessionWithPendingPaymentDoesNotReleaseStock()
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
        $this->purchaseFlow->expects($this->never())->method('rollback')->with($order);
        $this->mailService->expects($this->never())->method('sendOrderMail');

        $controller = $this->makeController();
        $controller->sessionCancel($this->callbackRequest());

        $this->assertSame('payment_pending', $controller->lastRedirect);
        $this->assertSame(OrderStatus::PENDING, $order->getOrderStatus()->getId());
    }

    public function testSessionCancelWithoutSessionIdStillRedirects()
    {
        $this->purchaseFlow->expects($this->never())->method('rollback');
        $this->mailService->expects($this->never())->method('sendOrderMail');

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
        $this->mailService->expects($this->never())->method('sendOrderMail');

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
        $this->mailService->expects($this->never())->method('sendOrderMail');

        $controller = $this->makeController();
        $req = $this->callbackRequest();
        $controller->sessionReturn($req);

        $this->assertSame('payment_pending', $controller->lastRedirect);
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

    public $pendingParameters;

    public function render($view, array $parameters = [])
    {
        $this->lastRedirect = 'payment_pending';
        $this->pendingParameters = $parameters;
        return (object) ['headers' => new \Symfony\Component\HttpFoundation\ParameterBag()];
    }

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

class CheckoutCartService extends CartService
{
    public $carts = [];
    public $current;
    public $failClear = false;

    public function __construct(array $carts, Cart $current)
    {
        foreach ($carts as $cart) {
            $this->carts[$cart->getId()] = $cart;
        }
        $this->current = $current;
    }

    public function getCart()
    {
        return $this->current;
    }

    public function clear()
    {
        if ($this->failClear) {
            throw new \RuntimeException('cart persistence unavailable');
        }
        if ($this->current) {
            unset($this->carts[$this->current->getId()]);
            $this->current = null;
        }
    }
}
