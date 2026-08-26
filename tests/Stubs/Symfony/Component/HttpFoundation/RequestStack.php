<?php

namespace Symfony\Component\HttpFoundation;

use Symfony\Component\HttpFoundation\Session\Session;

class RequestStack
{
    private $request;
    private $session;

    public function __construct(?Request $request = null, ?Session $session = null)
    {
        $this->request = $request ?: new Request();
        $this->session = $session ?: new Session();
    }

    public function getCurrentRequest() { return $this->request; }

    public function getSession() { return $this->session; }
}
