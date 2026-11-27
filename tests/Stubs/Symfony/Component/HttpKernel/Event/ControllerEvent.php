<?php

namespace Symfony\Component\HttpKernel\Event;

use Symfony\Component\HttpFoundation\Request;

class ControllerEvent
{
    private $request;
    private $controller;
    private $main;

    public function __construct($kernel, callable $controller, Request $request, int $requestType)
    {
        $this->controller = $controller;
        $this->request = $request;
        $this->main = $requestType === 1;
    }

    public function isMainRequest() { return $this->main; }
    public function getRequest() { return $this->request; }
    public function getController() { return $this->controller; }
    public function setController(callable $controller) { $this->controller = $controller; }
}
