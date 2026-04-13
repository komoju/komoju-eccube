<?php

namespace Symfony\Component\HttpFoundation;

class Request
{
    private $locale = 'ja';

    public function getLocale() { return $this->locale; }
    public function setLocale($locale) { $this->locale = $locale; }
}
