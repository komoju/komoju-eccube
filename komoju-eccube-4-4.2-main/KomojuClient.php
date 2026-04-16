<?php

namespace Plugin\Komoju42;

require_once __DIR__ . '/Resource/komoju_lib/init.php';

use Komoju\KomojuApi;
use Komoju\Payments;
use Komoju\Sessions;
use Komoju\PaymentMethods;

class KomojuClient{

    protected $secret_key;
    protected $api_obj;

    public function __construct($secret_key){
        $this->secret_key = $secret_key;
    }
    public function getPayments($page = null, $per_page = null){
        $this->api_obj = new Payments($this->secret_key);
        return $this->api_obj->get();
    }
    public function getPayment($payment_id){
        $this->api_obj = new Payments($this->secret_key);
        return $this->api_obj->getOne($payment_id);
    }
    public function createPayment($data){
        $this->api_obj = new Payments($this->secret_key);
        return $this->api_obj->create($data);
    }

    public function refundPayment($id, $data){
        $this->api_obj = new Payments($this->secret_key);
        return $this->api_obj->refund($id, $data);
    }
    public function capturePayment($payment_id){
        $this->api_obj = new Payments($this->secret_key);
        return $this->api_obj->capture($payment_id);
    }
    public function cancelPayment($payment_id){
        $this->api_obj = new Payments($this->secret_key);
        return $this->api_obj->cancel($payment_id);
    }
    public function createSession($data){
        $this->api_obj = new Sessions($this->secret_key);
        return $this->api_obj->create($data);
    }
    public function getSession($session_id){
        $this->api_obj = new Sessions($this->secret_key);
        return $this->api_obj->getOne($session_id);
    }
    public function getPaymentMethods(){
        $this->api_obj = new PaymentMethods($this->secret_key);
        return $this->api_obj->get();
    }

    public function getStatusCode(){
        if($this->api_obj){
            return $this->api_obj->getStatusCode();
        }
        return null;
    }
    public function getLastError(){
        if($this->api_obj){
            $error_code = $this->api_obj->getLastError();
            $trans_key = 'komoju_payment.error.' . $error_code;
            $translated = trans($trans_key);
            if($translated !== $trans_key){
                return $translated;
            }
            if(!empty($error_code)){
                return trans('komoju_payment.error.unknown_with_code', ['%code%' => $error_code]);
            }
            return trans('komoju_payment.error.unknown');
        }
        return null;
    }
}