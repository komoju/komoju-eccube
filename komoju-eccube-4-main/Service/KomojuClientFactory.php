<?php

namespace Plugin\Komoju\Service;

use Plugin\Komoju\KomojuClient;

class KomojuClientFactory{

    public function create($secret_key){
        return new KomojuClient($secret_key);
    }
}
