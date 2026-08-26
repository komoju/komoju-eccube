<?php

namespace Eccube\Service;

class OrderStateMachine
{
    public function can($Order, $OrderStatus) { return true; }
    public function apply($Order, $OrderStatus) {}
}
