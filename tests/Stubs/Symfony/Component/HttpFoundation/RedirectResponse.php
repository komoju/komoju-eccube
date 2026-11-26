<?php

namespace Symfony\Component\HttpFoundation;

class RedirectResponse
{
    private $url;
    public $headers;
    private $status;

    public function __construct($url, $status = 302) {
        $this->url = $url;
        $this->status = $status;
        $this->headers = new ParameterBag();
    }
    public function getTargetUrl() { return $this->url; }
    public function getStatusCode() { return $this->status; }
}
