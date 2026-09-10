<?php

namespace Plugin\Komoju42\Entity;

use Doctrine\ORM\Mapping as ORM;
use Eccube\Entity\Order;

/**
 * Order
 *
 * @ORM\Table(name="plg_komoju_order")
 * @ORM\Entity(repositoryClass="Plugin\Komoju42\Repository\KomojuOrderRepository")
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
     * @ORM\Column(name="payment_token", type="string", nullable=true)
     */
    private $payment_token;

    /**
     * @var string
     * @ORM\Column(name="komoju_payment_id", type="string", nullable=true)
     */
    private $komoju_payment_id;

    /**
     * @var string
     * @ORM\Column(name="komoju_session_id", type="string", nullable=true)
     */
    private $komoju_session_id;
    /** @ORM\Column(name="expected_amount", type="decimal", precision=12, scale=2, nullable=true) */
    private $expected_amount;

    /** @ORM\Column(name="expected_currency", type="string", length=3, nullable=true) */
    private $expected_currency;

    /** @ORM\Column(name="callback_token_hash", type="string", length=64, nullable=true) */
    private $callback_token_hash;

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
     * @ORM\Column(name="selected_refund_option", type="integer", options={"unsigned":true,"default":0,"comment":"1=full_refund, 2=full_amount_minus_fee, 3=partial_refund"}, nullable=true)
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
     * @var string
     * @ORM\Column(name="captured_amount", type="decimal", precision=12, scale=2, options={"unsigned":true}, nullable=true)
     */
    private $captured_amount;

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

    /**
     * Returns true only when the payment type supports manual capture via the
     * KOMOJU API. Deferred-payment methods (konbini, bank_transfer, pay_easy,
     * e-money, QR apps) capture automatically when the customer pays; calling
     * capture on them returns 422 not_capturable.
     *
     * null means the type was not stored (pre-1.3.x order or session-only row);
     * we leave those as capturable so legacy admin buttons continue to work.
     */


    public function getCapturedAmount(){
        return $this->captured_amount;
    }
    public function setCapturedAmount($captured_amount){
        $this->captured_amount = $captured_amount;
        return $this;
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
     * A refund id set has no inherent order, but it is persisted as a string and
     * compared as one by the webhook's compare-and-swap dedupe. Every writer must
     * therefore serialise it identically, or the same set of refunds looks like a
     * new one and gets recorded twice.
     */
    public static function canonicalRefundIds(array $refund_ids){
        $refund_ids = array_filter($refund_ids, function ($id) {
            return $id !== null && $id !== '';
        });
        $refund_ids = array_values(array_unique($refund_ids));
        sort($refund_ids);
        return implode(',', $refund_ids);
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
    public function getKomojuSessionId(){
        return $this->komoju_session_id;
    }
    public function setKomojuSessionId($komoju_session_id){
        $this->komoju_session_id = $komoju_session_id;
        return $this;
    }
    public function getExpectedAmount(){
        return $this->expected_amount;
    }
    public function setExpectedAmount($expected_amount){
        $this->expected_amount = $expected_amount;
        return $this;
    }
    public function getExpectedCurrency(){
        return $this->expected_currency;
    }
    public function setExpectedCurrency($expected_currency){
        $this->expected_currency = $expected_currency;
        return $this;
    }
    public function getCallbackTokenHash(){
        return $this->callback_token_hash;
    }
    public function setCallbackTokenHash($callback_token_hash){
        $this->callback_token_hash = $callback_token_hash;
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
