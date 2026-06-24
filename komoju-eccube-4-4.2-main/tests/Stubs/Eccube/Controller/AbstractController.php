<?php

namespace Eccube\Controller;

/**
 * Minimal stub of Eccube\Controller\AbstractController for unit tests.
 *
 * Real EC-CUBE installs ship a controller base class that exposes a handful
 * of Symfony-framework conveniences (`addFlash`, `redirectToRoute`, `json`,
 * `forwardToRoute`, `render`, ...). The plugin only uses three of them in
 * its public-facing controllers, so we stub those three and expose them as
 * overridable methods. Tests subclass the real controller and override
 * these to capture the side effects without booting Symfony.
 */
class AbstractController
{
    /**
     * Symfony's AbstractController::addFlash() pushes a notice into the
     * session flash bag. Tests override this to record calls.
     */
    public function addFlash($type, $message)
    {
        // no-op stub
    }

    /**
     * Symfony's AbstractController::redirectToRoute() returns a
     * RedirectResponse pointing at the named route. Tests override to
     * return a canned object that records the route name.
     */
    public function redirectToRoute($route, array $parameters = [], $status = 302)
    {
        return new \Symfony\Component\HttpFoundation\RedirectResponse('/route/' . $route);
    }

    /**
     * Symfony's AbstractController::json() builds a JsonResponse. The
     * plugin only uses it from WebhookController and passes a plain array.
     */
    public function json($data, $status = 200, array $headers = [], array $context = [])
    {
        return new \Symfony\Component\HttpFoundation\JsonResponse($data, $status, $headers);
    }
}
