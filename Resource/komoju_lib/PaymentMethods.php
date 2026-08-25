<?php

namespace Komoju;

class PaymentMethods extends KomojuApi{

    protected $url = '/api/v1/payment_methods';

    public function __construct($secret_key){
        parent::__construct($secret_key);
    }
    public function get(){
        return $this->http_client->get($this->url);
    }
}
