<?php

namespace Komoju;

class Payments extends KomojuApi{
    
    protected $url = '/api/v1/payments';

    public function __construct($secret_key){
        parent::__construct($secret_key);
    }
    public function get($page = null, $per_page = null){
        $data = [];
        if($page === null){
            $data = null;
        }else{
            $data = [
                'page'      =>  $page,
                'per_page'  =>  $per_page
            ];
        }
        return $this->http_client->get($this->url, $data);
    }
    public function getOne($payment_id){        
        return $res = $this->http_client->get($this->url . "/$payment_id");        
    }

    public function create($data){
        return $this->http_client->post($this->url, $data);
    }
    public function refund($id, $data){     
        return $this->http_client->post($this->url . "/$id/refund", $data);
    }
    public function capture($id){
        return $this->http_client->post($this->url . "/$id/capture");
    }
    public function cancel($id){
        return $this->http_client->post($this->url . "/$id/cancel");
    }
}