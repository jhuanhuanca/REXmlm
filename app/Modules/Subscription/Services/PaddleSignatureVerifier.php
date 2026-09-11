<?php

declare(strict_types=1);

namespace App\Modules\Subscription\Services;

class PaddleSignatureVerifier
{
    public function valid(string $signatureHeader, string $rawBody): bool
    {
        $secret = (string) config('services.paddle.webhook_secret');

        if ($secret === '' || $signatureHeader === '') {
            return false;
        }

        $parts = [];
        foreach (explode(';', $signatureHeader) as $chunk) {
            [$key, $value] = array_pad(explode('=', trim($chunk), 2), 2, '');
            $parts[$key] = $value;
        }

        $ts = $parts['ts'] ?? '';
        $h1 = $parts['h1'] ?? '';

        if ($ts === '' || $h1 === '' || ! ctype_digit($ts)) {
            return false;
        }

        if (abs(time() - (int) $ts) > 300) {
            return false;
        }

        $expected = hash_hmac('sha256', $ts.':'.$rawBody, $secret);

        return hash_equals($expected, $h1);
    }
}
