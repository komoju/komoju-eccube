<?php

namespace Eccube\Entity;

class OrderItem
{
    private $is_product = false;
    private $ProductClass;

    public function isProduct() { return $this->is_product; }
    public function setIsProduct($v) { $this->is_product = $v; return $this; }
    public function getProductClass() { return $this->ProductClass; }
    public function setProductClass($pc) { $this->ProductClass = $pc; return $this; }
}
