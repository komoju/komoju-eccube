<?php

namespace Plugin\komoju\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * KomojuPay
 * @ORM\Table(name="plg_komoju_multi_pays")
 * @ORM\Entity(repositoryClass="Plugin\komoju\Repository\KomojuPayRepository")
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
}