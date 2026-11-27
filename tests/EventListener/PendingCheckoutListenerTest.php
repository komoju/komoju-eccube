<?php

namespace Tests\Komoju42\EventListener;

use Doctrine\ORM\EntityManagerInterface;
use Eccube\Entity\Cart;
use Eccube\Entity\Master\OrderStatus;
use Eccube\Entity\Order;
use Eccube\Service\CartService;
use PHPUnit\Framework\TestCase;
use Plugin\Komoju42\Entity\KomojuOrder;
use Plugin\Komoju42\EventListener\PendingCheckoutListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Tests\Komoju42\Service\StubRepository;

class PendingCheckoutListenerTest extends TestCase
{
    /** @dataProvider guardedRoutes */
    public function testReservedCartCannotBeValidatedEditedOrOrderedAgain($route, $ajax)
    {
        $request = new Request([], $ajax ? ['X-Requested-With' => 'XMLHttpRequest'] : []);
        $request->attributes->set('_route', $route);
        $event = new ControllerEvent(null, static function () { throw new \LogicException('Core must not mutate the reserved cart'); }, $request, 1);
        [$listener, $order, $cartService] = $this->listener(OrderStatus::PENDING);
        $cartService->expects($ajax ? $this->never() : $this->once())->method('setPrimary')->with('held-cart');
        $listener->onController($event);
        $response = ($event->getController())();
        if ($ajax) {
            $this->assertFalse($response->getData()['done']);
            $this->assertSame('/status?session_id=session-held', $response->getData()['redirect_url']);
        } else {
            $this->assertSame(303, $response->getStatusCode());
            $this->assertSame('/status?session_id=session-held', $response->getTargetUrl());
        }
        $this->assertSame(OrderStatus::PENDING, $order->getOrderStatus()->getId());
    }

    public function guardedRoutes(): array
    {
        return array_map(static function ($route) { return [$route, $route === 'product_add_cart']; }, [
            'cart', 'cart_handle_item', 'cart_buystep', 'shopping', 'shopping_confirm',
            'shopping_checkout', 'shopping_redirect_to', 'shopping_shipping', 'product_add_cart', 'mypage_order',
        ]);
    }

    /** @dataProvider unguardedRoutes */
    public function testBrowsingAndPaymentCallbacksRemainAvailable($route, $requestType)
    {
        $request = new Request();
        $request->attributes->set('_route', $route);
        $original = static function () { return 'original'; };
        $event = new ControllerEvent(null, $original, $request, $requestType);
        [$listener, , $cartService] = $this->listener(OrderStatus::PENDING);
        $cartService->expects($this->never())->method('getCarts');
        $listener->onController($event);
        $this->assertSame($original, $event->getController());
    }

    public function unguardedRoutes(): array
    {
        return [
            ['homepage', 1], ['product_detail', 1], ['contact', 1], ['shopping_complete', 1],
            ['shopping_error', 1], ['shopping_login', 1], ['Komoju42_session_status', 1],
            ['Komoju42_session_return', 1], ['Komoju42_webhook', 1], ['admin_order', 1], ['cart', 2],
        ];
    }

    /** @dataProvider orderStates */
    public function testNormalAndRecoveredOrdersCanCheckoutButAcceptedOrdersCannotRepay($status, $guarded, $hasAttempt)
    {
        $request = new Request();
        $request->attributes->set('_route', 'shopping_checkout');
        $original = static function () { return 'original'; };
        $event = new ControllerEvent(null, $original, $request, 1);
        [$listener] = $this->listener($status, $hasAttempt);
        $listener->onController($event);
        $this->assertSame($guarded, $event->getController() !== $original);
    }

    public function orderStates(): array
    {
        return [
            [OrderStatus::PROCESSING, false, true], [OrderStatus::CANCEL, false, true],
            [OrderStatus::NEW, true, true], [OrderStatus::PAID, true, true],
            [OrderStatus::PENDING, false, false],
        ];
    }

    private function listener($status, $hasAttempt = true): array
    {
        $order = (new Order())->setOrderStatus((new OrderStatus())->setId($status));
        $attempt = (new KomojuOrder())->setOrder($order)->setKomojuSessionId('session-held');
        $ordinaryCart = (new Cart())->setPreOrderId('')->setCartKey('ordinary-cart');
        $heldCart = (new Cart())->setPreOrderId('pre-held')->setCartKey('held-cart');
        $cartService = $this->createMock(CartService::class);
        $cartService->method('getCarts')->willReturn([$ordinaryCart, $heldCart]);
        $cartService->expects($this->never())->method('clear');
        $orders = $this->createMock(StubRepository::class);
        $orders->method('findOneBy')->with(['pre_order_id' => 'pre-held'])->willReturn($order);
        $attempts = $this->createMock(StubRepository::class);
        $attempts->method('findOneBy')->willReturn($hasAttempt ? $attempt : null);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturnMap([[Order::class, $orders], [KomojuOrder::class, $attempts]]);
        $em->expects($this->never())->method('persist');
        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->method('generate')->willReturn('/status?session_id=session-held');
        return [new PendingCheckoutListener($em, $cartService, $router), $order, $cartService];
    }
}
