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
    /** @var ParameterBag */
    public $request;
    private $method = 'POST';

    public function __construct(array $query = [], array $headers = [], string $content = '', array $request = [])
    {
        $this->query = new ParameterBag($query);
        $this->headers = new ParameterBag($headers);
        $this->request = new ParameterBag($request);
        $this->content = $content;
    }

    public function getMethod() { return $this->method; }
    public function setMethod($method) { $this->method = $method; }

    public function getLocale() { return $this->locale; }
    public function setLocale($locale) { $this->locale = $locale; }

    public function getContent() { return $this->content; }
    public function setContent(string $content) { $this->content = $content; }
}
