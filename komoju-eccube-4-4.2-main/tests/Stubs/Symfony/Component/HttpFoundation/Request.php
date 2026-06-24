<?php

namespace Symfony\Component\HttpFoundation;

class Request
{
    private $locale = 'ja';
    private $content = '';

    /** @var ParameterBag */
    public $query;
    /** @var ParameterBag */
    public $headers;

    public function __construct(array $query = [], array $headers = [], string $content = '')
    {
        $this->query = new ParameterBag($query);
        $this->headers = new ParameterBag($headers);
        $this->content = $content;
    }

    public function getLocale() { return $this->locale; }
    public function setLocale($locale) { $this->locale = $locale; }

    public function getContent() { return $this->content; }
    public function setContent(string $content) { $this->content = $content; }
}
