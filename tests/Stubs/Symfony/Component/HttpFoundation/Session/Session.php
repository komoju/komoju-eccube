<?php

namespace Symfony\Component\HttpFoundation\Session;

/**
 * Minimal Session stub. Production session uses bags and storage; the
 * plugin only calls get / set / remove on the top-level session.
 */
class Session
{
    private $store = [];

    public function set($key, $value) { $this->store[$key] = $value; }
    public function get($key, $default = null) { return $this->store[$key] ?? $default; }
    public function remove($key) {
        $value = $this->get($key);
        unset($this->store[$key]);
        return $value;
    }
    public function all() { return $this->store; }
}
