<?php

namespace Komoju;

class Sessions extends KomojuApi{

    protected $url = '/api/v1/sessions';

    public function __construct($secret_key){
        parent::__construct($secret_key);
    }
    public function getOne($session_id){
        return $this->http_client->get($this->url . "/$session_id");
    }
    public function create($data){
        return $this->http_client->post($this->url, $data);
    }
}
