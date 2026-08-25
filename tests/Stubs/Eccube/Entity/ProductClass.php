<?php

namespace Eccube\Entity;

class ProductClass
{
    private $stock_unlimited = false;

    public function isStockUnlimited() { return $this->stock_unlimited; }
    public function setStockUnlimited($unlimited) { $this->stock_unlimited = $unlimited; return $this; }
}
