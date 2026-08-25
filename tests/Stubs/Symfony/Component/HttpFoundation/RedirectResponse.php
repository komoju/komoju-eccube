<?php

namespace Symfony\Component\HttpFoundation;

class RedirectResponse
{
    private $url;

    public function __construct($url) { $this->url = $url; }
    public function getTargetUrl() { return $this->url; }
}
