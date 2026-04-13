<?php

namespace Eccube\Service\PurchaseFlow;

class PurchaseFlow
{
    public function prepare($Order, $context) {}
    public function commit($Order, $context) {}
    public function rollback($Order, $context) {}
}
