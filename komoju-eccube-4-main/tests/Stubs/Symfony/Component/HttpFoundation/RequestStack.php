<?php

namespace Symfony\Component\HttpFoundation;

class RequestStack
{
    private $request;

    public function __construct(?Request $request = null)
    {
        $this->request = $request ?: new Request();
    }

    public function getCurrentRequest() { return $this->request; }
}
