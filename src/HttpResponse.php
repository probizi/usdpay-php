<?php

declare(strict_types=1);

namespace USDPAY\SDK;

final class HttpResponse
{
    /**
     * @var array<string, string>
     */
    private array $headers = [];

    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        array $headers = []
    ) {
        foreach ($headers as $name => $value) {
            $this->headers[strtolower($name)] = $value;
        }
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
