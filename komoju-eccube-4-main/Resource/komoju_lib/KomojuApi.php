<?php

namespace Komoju;

class KomojuApi{

    protected $secret_key;
    protected $http_client;

    public function __construct($secret_key){
        $this->secret_key = $secret_key;
        $this->http_client = new HttpClient($this->secret_key, "");
    }
    public function getLastError(){
        return $this->http_client->getLastError();
    }
    public function getStatusCode(){
        return $this->http_client->getStatusCode();
    }
}

