<?php

/**
 * Stub for EC-CUBE/Symfony trans() helper used in plugin code.
 */
if (!function_exists('trans')) {
    function trans($key, $params = []) {
        $result = $key;
        foreach ($params as $k => $v) {
            $result = str_replace($k, $v, $result);
        }
        return $result;
    }
}

if (!function_exists('log_warning')) {
    function log_warning($message) {
        // no-op in tests
    }
}

if (!function_exists('log_error')) {
    function log_error($message) {
        // no-op in tests
    }
}

if (!function_exists('log_info')) {
    function log_info($message) {
        // no-op in tests
    }
}
