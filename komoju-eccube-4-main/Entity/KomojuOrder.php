<?php

namespace Plugin\komoju\Entity;

use Doctrine\ORM\Mapping as ORM;
use Eccube\Entity\Order;

/**
 * Order
 *
 * @ORM\Table(name="plg_komoju_order")
 * @ORM\Entity(repositoryClass="Plugin\komoju\Repository\KomojuOrderRepository")
 */
class KomojuOrder
{
    const REFUND_FULL = 1;
    const REFUND_PARTIAL = 2;
    const REFUND_UNKNOWN = 3;
    /**
     * @var int
     *
     * @ORM\Column(name="id", type="integer", options={"unsigned":true})
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    private $id;

    /**
     * @var Order
     *
     * @ORM\ManyToOne(targetEntity="Eccube\Entity\Order")
     * @ORM\JoinColumns({
     *   @ORM\JoinColumn(name="order_id", referencedColumnName="id")
     * })
     */
    private $Order;

    /**
     * @var string
     * @ORM\Column(name="payment_token", type="string")
     */
    private $payment_token;

    /**
     * @var string
     * @ORM\Column(name="komoju_payment_id", type="string")
     */
    private $komoju_payment_id;
    /**
     * @var string
     * @ORM\Column(name="type", type="string", nullable=true)
     */
    private $type;


    /**
     * @var string
     * @ORM\Column(name="refund_id", type="string", nullable=true)
     */
    private $refund_id;

    /**
     * @var string
     * @ORM\Column(name="refund_request_id", type="string", nullable=true)
     */

    /**
     * @var int
     *
     * @ORM\Column(name="selected_refund_option", type="integer", options={"unsigned":true,"default":0,"comment":"1=full_refund, 2=refund_full_amount_minus_stripe_fee, 3=partial_refund"}, nullable=true)
     */
    private $selected_refund_option;

    /**
     * @var string
     *
     * @ORM\Column(name="refunded_amount", type="decimal", precision=12, scale=2, options={"unsigned":true,"default":0})
     */
    private $refunded_amount = 0;


    /**
     * @var \DateTime
     * @ORM\Column(name="created_at", type="datetime")
     */
    private $created_at;

    /**
     * @var \DateTime
     * @ORM\Column(name="captured_at", type="datetime", nullable=true)
     */
    private $captured_at;

    /**
     * @var \DateTime
     * @ORM\Column(name="canceled_at", type="datetime", nullable=true)
     */
    private $canceled_at;

    public function getCanceledAt(){
        return $this->canceled_at;
    }
    public function setCanceledAt($canceled_at){
        $this->canceled_at = $canceled_at;
        return $this;
    }

    public function isCreditType(){
        return $this->type == "credit_card";
    }

    public function getCapturedAt(){
        return $this->captured_at;
    }
    public function setCapturedAt($captured_at){
        $this->captured_at = $captured_at;
        return $this;
    }
    public function isCaptured(){
        return !empty($this->captured_at);
    }
    public function getRefundId(){
        return $this->refund_id;
    }
    public function setRefundId($refund_id){
        $this->refund_id = $refund_id;
        return $this;
    }
    /**
     * @return string
     */
    public function getRefundedAmount()
    {
        return $this->refunded_amount;
    }

    /**
     * @param string $refunded_amount
     *
     * @return $this;
     */
    public function setRefundedAmount($refunded_amount)
    {
        $this->refunded_amount = $refunded_amount;

        return $this;
    }
    /**
     * @return int
     */
    public function getSelectedRefundOption()
    {
        return $this->selected_refund_option;
    }

    /**
     * @param int $selected_refund_option
     *
     * @return $this;
     */
    public function setSelectedRefundOption($selected_refund_option)
    {
        $this->selected_refund_option = $selected_refund_option;

        return $this;
    }

    /**
     * @return boolean
     */
    public function getIsChargeRefunded()
    {
        return $this->refund_id ? true : false;
    }

    public function getType(){
        return $this->type;
    }
    public function setType($type){
        $this->type = $type;
        return $this;
    }

    /**
     * @return int
     */
    public function getId(){
        return $this->id;
    }
    public function setOrder(Order $Order){
        $this->Order = $Order;
        return $this;
    }
    public function getOrder(){
        return $this->Order;
    }
    public function getPaymentToken(){
        return $this->payment_token;
    }
    public function setPaymentToken($payment_token){
        $this->payment_token = $payment_token;
        return $this;
    }
    public function getKomojuPaymentId(){
        return $this->komoju_payment_id;
    }
    public function setKomojuPaymentId($komoju_payment_id){
        $this->komoju_payment_id = $komoju_payment_id;
        return $this;
    }
    public function setCreatedAt($created_at){
        $this->created_at = $created_at;
        return $this;
    }
    public function getCreatedAt(){
        return $this->created_at;
    }
}
