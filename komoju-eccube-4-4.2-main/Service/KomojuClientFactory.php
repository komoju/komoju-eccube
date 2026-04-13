<?php

namespace Plugin\Komoju42\Service;

use Plugin\Komoju42\KomojuClient;

class KomojuClientFactory{

    public function create($secret_key){
        return new KomojuClient($secret_key);
    }
}
