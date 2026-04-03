<?php

namespace Komoju;

class HttpClient{

    protected $username;
    protected $password;
    protected $curl_hdl;

    const BASE_URL = "https://komoju.com";

    //---http error------
    protected $last_error;
    protected $last_status_code;

    public function __construct($username, $password = ""){
        $this->username = $username;
        $this->password = $password;
        $this->curl_hdl = curl_init();
    }
    public function getLastError(){
        return $this->last_error;
    }
    public function getStatusCode(){
        return $this->last_status_code;
    }
    public function get($url, $data = null){
        if($data){
            $query = \http_build_query($data);
            $tar_url = self::BASE_URL . $url . "?" . $query;
        }else{
            $tar_url = self::BASE_URL . $url;
        }
        \curl_setopt($this->curl_hdl, CURLOPT_USERPWD, $this->username . ":" . $this->password);
        \curl_setopt($this->curl_hdl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);

        \curl_setopt($this->curl_hdl, CURLOPT_RETURNTRANSFER, true);
        \curl_setopt($this->curl_hdl, CURLOPT_URL, $tar_url);

        \curl_setopt($this->curl_hdl, CURLOPT_FOLLOWLOCATION, true);
        \curl_setopt($this->curl_hdl, CURLOPT_HTTPHEADER, array('Accept: application/json', 'Content-Type: application/json'));
        \curl_setopt($this->curl_hdl, CURLOPT_VERBOSE, false);

        \curl_setopt($this->curl_hdl, CURLOPT_SSL_VERIFYHOST, 2);
        \curl_setopt($this->curl_hdl, CURLOPT_SSL_VERIFYPEER, true);

        \curl_setopt($this->curl_hdl, CURLOPT_CONNECTTIMEOUT, 10);
        \curl_setopt($this->curl_hdl, CURLOPT_TIMEOUT, 30);

        $response = \curl_exec($this->curl_hdl);
        $resp = \json_decode($response,  true);

        $this->last_status_code = \curl_getinfo($this->curl_hdl, CURLINFO_HTTP_CODE);
        if($this->last_status_code >= 300){
            $this->last_error = $resp['error']['code'];
        }

        \curl_close($this->curl_hdl);
        return $resp;
    }
    public function post($url, $data = null){
        \curl_setopt($this->curl_hdl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        \curl_setopt($this->curl_hdl, CURLOPT_USERPWD, $this->username . ":" . $this->password);

        \curl_setopt($this->curl_hdl, CURLOPT_RETURNTRANSFER, true);
        \curl_setopt($this->curl_hdl, CURLOPT_URL, self::BASE_URL . $url);

        // post_data
        \curl_setopt($this->curl_hdl, CURLOPT_POST, true);
        if($data){
            \curl_setopt($this->curl_hdl, CURLOPT_POSTFIELDS, \json_encode($data));
        }
        \curl_setopt($this->curl_hdl, CURLOPT_FOLLOWLOCATION, true);
        \curl_setopt($this->curl_hdl, CURLOPT_HTTPHEADER, array('Accept: application/json', 'Content-Type: application/json'));

        \curl_setopt($this->curl_hdl, CURLOPT_VERBOSE, false);

        \curl_setopt($this->curl_hdl, CURLOPT_SSL_VERIFYHOST, 2);
        \curl_setopt($this->curl_hdl, CURLOPT_SSL_VERIFYPEER, true);

        \curl_setopt($this->curl_hdl, CURLOPT_CONNECTTIMEOUT, 10);
        \curl_setopt($this->curl_hdl, CURLOPT_TIMEOUT, 30);

        $response = \curl_exec($this->curl_hdl);

        $body = null;
        // error
        if (!$response) {
            $this->last_error = \curl_error($this->curl_hdl);
            // HostNotFound, No route to Host, etc  Network related error
            $http_status = -1;
            $body = null;
        } else {
        //parsing http status code
            $http_status = \curl_getinfo($this->curl_hdl, CURLINFO_HTTP_CODE);
            $body = $response;
        }
        $this->last_status_code = $http_status;
        \curl_close($this->curl_hdl);
        $resp = \json_decode($body, true);
        if($this->last_status_code >= 300){
            $this->last_error = $resp['error']['code'];
        }

        return $resp;
    }

    private function header(){
        return "Authorization: Basic " . \base64_encode($this->username . ":" . $this->password);
    }
}