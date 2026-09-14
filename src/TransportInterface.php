<?php

declare(strict_types=1);

namespace USDPAY\SDK;

interface TransportInterface
{
    /**
     * @param array<string, string> $headers
     */
    public function send(
        string $method,
        string $url,
        array $headers,
        ?string $body,
        int $timeout,
        int $connectTimeout
    ): HttpResponse;
}
