<?php

namespace Symfony\Component\HttpFoundation;

/**
 * Minimal ParameterBag stub used as the backing store for Request::$query
 * and Request::$headers. Real Symfony has many more methods; the plugin
 * only uses ->get() and ->set() (the latter in tests for arrangement).
 */
class ParameterBag
{
    private $params;

    public function __construct(array $params = [])
    {
        $this->params = $params;
    }

    public function get($key, $default = null)
    {
        return array_key_exists($key, $this->params) ? $this->params[$key] : $default;
    }

    public function set($key, $value)
    {
        $this->params[$key] = $value;
    }

    public function all()
    {
        return $this->params;
    }
}
