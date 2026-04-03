<?php

namespace Komoju;

class WebhookEvent{

    public static function constructEvent($payload, $sigHeader, $secret){
        $res = WebhookSignature::verifyHeader($payload, $sigHeader, $secret);
        if(empty($res)){
            throw new \RuntimeException("verify_error");
        }
        $data = \json_decode($payload);
        $jsonError = \json_last_error();
        if (null === $data && \JSON_ERROR_NONE !== $jsonError) {
            $msg = "Invalid payload (json_last_error() was {$jsonError})";

            throw new \UnexpectedValueException($msg);
        }
        return $data;
    }
}