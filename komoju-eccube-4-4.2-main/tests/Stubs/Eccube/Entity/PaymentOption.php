<?php

namespace Eccube\Entity;

class PaymentOption
{
    private $payment_id;
    private $delivery_id;
    private $Payment;
    private $Delivery;

    public function getPaymentId() { return $this->payment_id; }
    public function setPaymentId($id) { $this->payment_id = $id; return $this; }
    public function getDeliveryId() { return $this->delivery_id; }
    public function setDeliveryId($id) { $this->delivery_id = $id; return $this; }
    public function getPayment() { return $this->Payment; }
    public function setPayment($p) { $this->Payment = $p; return $this; }
    public function getDelivery() { return $this->Delivery; }
    public function setDelivery($d) { $this->Delivery = $d; return $this; }
}
