<?php

namespace Eccube\Entity;

class Delivery
{
    private $id;
    private $visible;
    private $paymentOptions = [];

    public function getId() { return $this->id; }
    public function setId($id) { $this->id = $id; return $this; }
    public function getVisible() { return $this->visible; }
    public function setVisible($v) { $this->visible = $v; return $this; }
    public function addPaymentOption($option) { $this->paymentOptions[] = $option; return $this; }
    public function getPaymentOptions() { return $this->paymentOptions; }
}
