<?php

declare(strict_types=1);

namespace USDPAY\SDK\Tests;

use Closure;
use USDPAY\SDK\HttpResponse;
use USDPAY\SDK\TransportInterface;

final class RecordingTransport implements TransportInterface
{
    /**
     * @var list<array<string, mixed>>
     */
    public array $requests = [];

    private Closure $handler;

    public function __construct(callable $handler)
    {
        $this->handler = Closure::fromCallable($handler);
    }

    public function send(
        string $method,
        string $url,
        array $headers,
        ?string $body,
        int $timeout,
        int $connectTimeout
    ): HttpResponse {
        $request = compact('method', 'url', 'headers', 'body', 'timeout', 'connectTimeout');
        $this->requests[] = $request;

        return ($this->handler)($request);
    }
}
