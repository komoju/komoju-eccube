<?php

namespace Eccube\Entity;

class Cart
{
    private $id;
    private $pre_order_id;
    private $cart_key;

    public function getId() { return $this->id; }
    public function getPreOrderId() { return $this->pre_order_id; }
    public function setPreOrderId($id) { $this->pre_order_id = $id; return $this; }
    public function getCartKey() { return $this->cart_key; }
    public function setCartKey(string $key) { $this->cart_key = $key; return $this; }
}
