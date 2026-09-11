<?php

namespace Eccube\Entity;

use Eccube\Entity\Master\OrderStatus;

class Order
{
    private $id;
    private $Payment;
    private $OrderStatus;
    private $payment_total;
    private $Customer;
    private $OrderItems = [];
    private $payment_date;
    private $order_date;
    private $order_no;
    private $currency_code;
    private $pre_order_id;

    public function getId() { return $this->id; }
    public function setId($id) { $this->id = $id; return $this; }
    public function getPayment() { return $this->Payment; }
    public function setPayment($Payment) { $this->Payment = $Payment; return $this; }
    public function getOrderStatus() { return $this->OrderStatus; }
    public function setOrderStatus($OrderStatus) { $this->OrderStatus = $OrderStatus; return $this; }
    public function getPaymentTotal() { return $this->payment_total; }
    public function setPaymentTotal($total) { $this->payment_total = $total; return $this; }
    public function getCustomer() { return $this->Customer; }
    public function setCustomer($Customer) { $this->Customer = $Customer; return $this; }
    public function getOrderItems() { return $this->OrderItems; }
    public function setOrderItems($items) { $this->OrderItems = $items; return $this; }
    public function getPaymentDate() { return $this->payment_date; }
    public function setPaymentDate($date) { $this->payment_date = $date; return $this; }
    public function getOrderDate() { return $this->order_date; }
    public function setOrderDate($date) { $this->order_date = $date; return $this; }
    public function getOrderNo() { return $this->order_no; }
    public function setOrderNo($no) { $this->order_no = $no; return $this; }
    public function getCurrencyCode() { return $this->currency_code; }
    public function setCurrencyCode($code) { $this->currency_code = $code; return $this; }
    public function getPreOrderId() { return $this->pre_order_id; }
    public function setPreOrderId($id) { $this->pre_order_id = $id; return $this; }
}
