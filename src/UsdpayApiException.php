<?php

declare(strict_types=1);

namespace USDPAY\SDK;

use RuntimeException;

final class UsdpayApiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $httpStatus = 0,
        public readonly string $apiCode = 'request_failed',
        public readonly mixed $details = null,
        public readonly int|string|null $retryAfter = null,
        public readonly ?string $requestId = null
    ) {
        parent::__construct($message);
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    public function getApiCode(): string
    {
        return $this->apiCode;
    }

    public function getDetails(): mixed
    {
        return $this->details;
    }

    public function getRetryAfter(): int|string|null
    {
        return $this->retryAfter;
    }

    public function getRequestId(): ?string
    {
        return $this->requestId;
    }
}
