<?php

namespace Komoju;

abstract class WebhookSignature
{
    /**
     * @return book
     */
    public static function verifyHeader($payload, $sig_header, $secret)
    {
        
        $expectedSignature = self::computeSignature($payload, $secret);
        return self::secureCompare($expectedSignature, $sig_header);
    }


   
    /**
     * Computes the signature for a given payload and secret.
     *
     * The current scheme is HMAC/SHA-256.
     *
     * @param string $payload the payload to sign
     * @param string $secret the secret used to generate the signature
     *
     * @return string the signature as a string
     */
    private static function computeSignature($payload, $secret)
    {
        return \hash_hmac('sha256', $payload, $secret);
    }
    private static function secureCompare($a, $b){
        $hashEqualsAvailable = \function_exists("hash_equals");
        if($hashEqualsAvailable){
            return \hash_equals($a, $b);
        }
        if (\strlen($a) !== \strlen($b)) {
            return false;
        }

        $result = 0;
        for ($i = 0; $i < \strlen($a); ++$i) {
            $result |= \ord($a[$i]) ^ \ord($b[$i]);
        }

        return 0 === $result;
    }
}
