<?php

namespace Komoju;

abstract class WebhookSignature
{
    /**
     * @return bool
     */
    public static function verifyHeader($payload, $sig_header, $secret)
    {
        // A missing X-Komoju-Signature header arrives as null from Symfony's
        // HeaderBag::get(). hash_equals() / strlen() in secureCompare both
        // raise TypeError on null in PHP 8+, which WebhookController's
        // try/catch(\Exception) does NOT catch (TypeError extends \Error).
        // Coerce to an empty string so the comparison fails cleanly and the
        // controller can return 400 / log a verification failure.
        if ($sig_header === null) {
            return false;
        }
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
