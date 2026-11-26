<?php

namespace Plugin\Komoju42\EventListener;

use Doctrine\ORM\EntityManagerInterface;
use Eccube\Entity\Master\OrderStatus;
use Eccube\Entity\Order;
use Eccube\Service\CartService;
use Plugin\Komoju42\Entity\KomojuOrder;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** Keep core cart validation from consuming stock reserved by this very cart. */
class PendingCheckoutListener implements EventSubscriberInterface
{
    private $entityManager;
    private $cartService;
    private $router;

    public function __construct(EntityManagerInterface $entityManager, CartService $cartService, UrlGeneratorInterface $router)
    {
        $this->entityManager = $entityManager;
        $this->cartService = $cartService;
        $this->router = $router;
    }

    public static function getSubscribedEvents(): array
    {
        return ['kernel.controller' => ['onController', 10]];
    }

    public function onController(ControllerEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        $route = (string) $request->attributes->get('_route');
        if (!($route === 'cart' || strpos($route, 'cart_') === 0
            || $route === 'shopping' || strpos($route, 'shopping_') === 0
            || in_array($route, ['product_add_cart', 'mypage_order'], true))
            || in_array($route, ['shopping_complete', 'shopping_error', 'shopping_login'], true)) {
            return;
        }

        // Core add/reorder and cart validation can touch every cart, not only the
        // primary one. Do not allow an unrelated cart edit to destroy a held cart.
        foreach ($this->cartService->getCarts() as $cart) {
            if (!$cart->getPreOrderId()) {
                continue;
            }
            $order = $this->entityManager->getRepository(Order::class)->findOneBy([
                'pre_order_id' => $cart->getPreOrderId(),
            ]);
            if (!$order || in_array($order->getOrderStatus()->getId(), [OrderStatus::PROCESSING, OrderStatus::CANCEL, OrderStatus::RETURNED], true)) {
                continue;
            }
            $attempt = $this->entityManager->getRepository(KomojuOrder::class)->findOneBy(['Order' => $order], ['id' => 'DESC']);
            if (!$attempt || !$attempt->getKomojuSessionId()) {
                continue;
            }

            if (!$request->isXmlHttpRequest()) {
                $this->cartService->setPrimary($cart->getCartKey());
            }
            // The status endpoint independently checks the browser binding before
            // fetching payment details or exposing a receipt/resume link.
            $url = $this->router->generate('Komoju42_session_status', ['session_id' => $attempt->getKomojuSessionId()]);
            $response = $request->isXmlHttpRequest()
                ? new JsonResponse(['done' => false, 'messages' => [trans('komoju_payment.shopping.checkout_locked')], 'redirect_url' => $url])
                : new RedirectResponse($url, 303);
            $response->headers->set('Cache-Control', 'no-store, private');
            $event->setController(static function () use ($response) { return $response; });
            return;
        }
    }
}
