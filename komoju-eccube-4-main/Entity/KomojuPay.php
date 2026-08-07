<?php

namespace Plugin\Komoju\Entity;

use Doctrine\ORM\Mapping as ORM;
use Eccube\Entity\Payment;

/**
 * KomojuPay
 * @ORM\Table(name="plg_komoju_payments")
 * @ORM\Entity(repositoryClass="Plugin\Komoju\Repository\KomojuPayRepository")
 */

class KomojuPay extends \Eccube\Entity\Master\AbstractMasterEntity{

    const TYPE_CREDIT_CARD = "credit_card";
    const TYPE_KOBINI = "konbini";
    const TYPE_BANK_TRANSFER = "bank_transfer";
    const TYPE_PAY_EASY = "pay_easy";
    const TYPE_WEB_MONEY = "web_money";
    const TYPE_BIT_CASH = "bit_cash";
    const TYPE_NET_CASH = "net_cash";

    /**
     * @var string
     *
     * @ORM\Column(name="disp_name", type="text", nullable=true)
     */
    private $disp_name;

    /**
     * @var boolean
     *
     * @ORM\Column(name="enabled", type="smallint", options={"default" : 0}, nullable=true)
     */
    private $enabled;

    /**
     * @var int|null
     *
     * @ORM\Column(name="payment_id", type="integer", nullable=true, options={"unsigned":true})
     */
    private $payment_id;

    /**
     * @var Payment|null
     *
     * @ORM\ManyToOne(targetEntity="Eccube\Entity\Payment")
     * @ORM\JoinColumn(name="payment_id", referencedColumnName="id", nullable=true)
     */
    private $Payment;

    public function isEnabled(){
        return $this->enabled > 0;
    }
    public function setEnabled($enabled){
        $this->enabled = $enabled;
        return $this;
    }
    public function getDispName(){
        return $this->disp_name;
    }
    public function setDispName($disp_name){
        $this->disp_name = $disp_name;
        return $this;
    }
    public function getPaymentId(){
        return $this->payment_id;
    }
    public function setPaymentId($payment_id){
        $this->payment_id = $payment_id;
        return $this;
    }
    public function getPayment(){
        return $this->Payment;
    }
    public function setPayment($Payment){
        $this->Payment = $Payment;
        $this->payment_id = $Payment ? $Payment->getId() : null;
        return $this;
    }
}