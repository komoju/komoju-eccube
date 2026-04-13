<?php

namespace Komoju;

class HttpClient{

    protected $username;
    protected $password;
    protected $curl_hdl;

    const DEFAULT_BASE_URL = "https://komoju.com";

    //---http error------
    protected $last_error;
    protected $last_status_code;
    protected $base_url;

    public function __construct($username, $password = ""){
        $this->username = $username;
        $this->password = $password;
        $this->base_url = getenv('KOMOJU_API_URL') ?: self::DEFAULT_BASE_URL;
    }

    protected function isSSL(){
        return strpos($this->base_url, 'https://') === 0;
    }
    public function getLastError(){
        return $this->last_error;
    }
    public function getStatusCode(){
        return $this->last_status_code;
    }
    private function initCurl($url){
        $ch = \curl_init();
        \curl_setopt($ch, CURLOPT_USERPWD, $this->username . ":" . $this->password);
        \curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        \curl_setopt($ch, CURLOPT_URL, $url);
        \curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        \curl_setopt($ch, CURLOPT_HTTPHEADER, array('Accept: application/json', 'Content-Type: application/json', 'KOMOJU-VIA: ec_cube'));
        \curl_setopt($ch, CURLOPT_VERBOSE, false);
        \curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $this->isSSL() ? 2 : 0);
        \curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $this->isSSL());
        \curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        \curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        return $ch;
    }
    private function decodeResponse($body){
        $resp = \json_decode($body, true);
        if ($resp === null && \json_last_error() !== JSON_ERROR_NONE) {
            $this->last_error = 'json_decode_error: ' . \json_last_error_msg();
        }
        return $resp;
    }
    public function get($url, $data = null){
        if($data){
            $query = \http_build_query($data);
            $tar_url = $this->base_url . $url . "?" . $query;
        }else{
            $tar_url = $this->base_url . $url;
        }
        $this->curl_hdl = $this->initCurl($tar_url);

        $response = \curl_exec($this->curl_hdl);
        if (!$response) {
            $this->last_error = \curl_error($this->curl_hdl);
            $this->last_status_code = -1;
            \curl_close($this->curl_hdl);
            return null;
        }

        $this->last_status_code = \curl_getinfo($this->curl_hdl, CURLINFO_HTTP_CODE);
        \curl_close($this->curl_hdl);

        $resp = $this->decodeResponse($response);
        if($this->last_status_code >= 300){
            $this->last_error = isset($resp['error']['code']) ? $resp['error']['code'] : 'unknown_error';
        }

        return $resp;
    }
    public function post($url, $data = null){
        $this->curl_hdl = $this->initCurl($this->base_url . $url);

        \curl_setopt($this->curl_hdl, CURLOPT_POST, true);
        if($data){
            \curl_setopt($this->curl_hdl, CURLOPT_POSTFIELDS, \json_encode($data));
        }

        $response = \curl_exec($this->curl_hdl);
        if (!$response) {
            $this->last_error = \curl_error($this->curl_hdl);
            $this->last_status_code = -1;
            \curl_close($this->curl_hdl);
            return null;
        }

        $this->last_status_code = \curl_getinfo($this->curl_hdl, CURLINFO_HTTP_CODE);
        \curl_close($this->curl_hdl);

        $resp = $this->decodeResponse($response);
        if($this->last_status_code >= 300){
            $this->last_error = isset($resp['error']['code']) ? $resp['error']['code'] : 'unknown_error';
        }

        return $resp;
    }
}
