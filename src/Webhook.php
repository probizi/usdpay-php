<?php

declare(strict_types=1);

namespace USDPAY\SDK;

use InvalidArgumentException;

final class Webhook
{
    public static function verifySignature(
        string $rawBody,
        string $signature,
        string $secret
    ): bool {
        if ($secret === '') {
            throw new InvalidArgumentException('Webhook secret is required');
        }

        $expected = 'sha256=' . hash_hmac('sha256', $rawBody, $secret);

        return hash_equals($expected, $signature);
    }
}
