<?php

namespace Plugin\Komoju\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * KomojuLog
 * @ORM\Table(name="plg_komoju_log")
 * @ORM\Entity(repositoryClass="Plugin\Komoju\Repository\KomojuLogRepository")
 */

class KomojuLog{
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
     * @ORM\Column(name="api", type="string", length=255, nullable=true)
     */
    private $api;

    /**
     * @var string
     *
     * @ORM\Column(name="order_id", type="string", length=255, nullable=true)
     */
    private $order_id;

    /**
     * @var string
     * @ORM\Column(name="msg", type="text", nullable=true)
     */
    private $msg;

    /**
     * @var int
     *
     * @ORM\Column(name="is_protected", type="smallint", options={"default":0}, nullable=true)
     */
    private $is_protected = 0;

    /**
     * @var \DateTime
     *
     * @ORM\Column(name="created_at", type="datetime")
     */
    private $created_at;

    public function getId(){
        return $this->id;
    }
    public function getApi(){
        return $this->api;
    }
    public function setApi($api){
        $this->api = $api;
        return $this;
    }
    public function getOrderId(){
        return $this->order_id;
    }
    public function setOrderId($order_id){
        $this->order_id = $order_id;
        return $this;
    }
    public function getMsg(){
        return $this->msg;
    }
    public function setMsg($msg){
        $this->msg = $msg;
        return $this;
    }
    public function setCreatedAt($created_at){
        $this->created_at = $created_at;
        return $this;
    }
    public function getCreatedAt(){
        return $this->created_at;
    }
    public function getIsProtected(){
        return $this->is_protected;
    }
    public function setIsProtected($is_protected){
        $this->is_protected = $is_protected ? 1 : 0;
        return $this;
    }
}