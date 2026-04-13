<?php

namespace Plugin\Komoju\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Config
 *
 * @ORM\Table(name="plg_komoju_config")
 * @ORM\Entity(repositoryClass="Plugin\Komoju\Repository\KomojuConfigRepository")
 */
class KomojuConfig
{
    /**
     * @var int
     *
     * @ORM\Column(name="id", type="integer", options={"unsigned":true})
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="IDENTITY")
     */
    private $id;

    /**
     * @var string
     *
     * @ORM\Column(name="publishable_key", type="string", length=255, nullable=true)
     */
    private $publishable_key;

    /**
     * @var string
     *
     * @ORM\Column(name="secret_key", type="string", length=255, nullable=true)
     */
    private $secret_key;

    /**
     * @var string
     *
     * @ORM\Column(name="merchant_uuid", type="string", length=255, nullable=true)
     */
    private $merchant_uuid;
    /**
     * @var boolean
     *
     * @ORM\Column(name="capture_on", type="smallint", options={"default" : 0}, nullable=true)
     */
    private $capture_on;

    /**
     * @var string
     *
     * @ORM\Column(name="webhook_secret", type="string", length=255, nullable=true)
     */
    private $webhook_secret;

    /**
     * @var int|null
     *
     * @ORM\Column(name="log_retention_days", type="integer", nullable=true)
     */
    private $log_retention_days;

    /**
     * @var boolean
     *
     * @ORM\Column(name="logging_enabled", type="smallint", options={"default" : 1}, nullable=true)
     */
    private $logging_enabled = 1;

    /**
     * @var string|null
     *
     * @ORM\Column(name="order_number_format", type="string", length=255, nullable=true)
     */
    private $order_number_format;

    public function getWebhookSecret(){
        return $this->webhook_secret;
    }
    public function setWebhookSecret($webhook_secret){
        $this->webhook_secret = $webhook_secret;
        return $this;
    }

    public function getId(){
        return $this->id;
    }
    public function isCaptureOn(){
        return $this->capture_on > 0;
    }
    public function setCaptureOn($capture_on){
        $this->capture_on = $capture_on;
        return $this;
    }
    public function getSecretKey(){
        return $this->secret_key;
    }

    public function setSecretKey($secret_key){
        $this->secret_key = $secret_key;
        return $this;
    }
    public function getPublishableKey(){
        return $this->publishable_key;
    }
    public function setPublishableKey($publishable_key){
        $this->publishable_key = $publishable_key;
        return $this;
    }
    public function getMerchantUuid(){
        return $this->merchant_uuid;
    }
    public function setMerchantUuid($merchant_uuid){
        $this->merchant_uuid = $merchant_uuid;
    }
    public function isLoggingEnabled(){
        return $this->logging_enabled > 0;
    }
    public function setLoggingEnabled($logging_enabled){
        $this->logging_enabled = $logging_enabled ? 1 : 0;
        return $this;
    }
    public function getLogRetentionDays(){
        return $this->log_retention_days;
    }
    public function setLogRetentionDays($log_retention_days){
        $this->log_retention_days = $log_retention_days;
        return $this;
    }
    public function getOrderNumberFormat(){
        return $this->order_number_format;
    }
    public function setOrderNumberFormat($order_number_format){
        $this->order_number_format = $order_number_format;
        return $this;
    }
}