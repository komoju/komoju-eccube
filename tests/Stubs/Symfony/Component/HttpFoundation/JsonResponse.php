<?php

namespace Symfony\Component\HttpFoundation;

/**
 * Minimal JsonResponse stub. Just needs to expose the data, status code,
 * and headers so tests can assert on them.
 */
class JsonResponse
{
    private $data;
    private $status;
    public $headers;

    public function __construct($data = null, int $status = 200, array $headers = [])
    {
        $this->data = $data;
        $this->status = $status;
        $this->headers = new ParameterBag($headers);
    }

    public function getData() { return $this->data; }
    public function getStatusCode() { return $this->status; }
    public function getHeaders() { return $this->headers->all(); }
}
