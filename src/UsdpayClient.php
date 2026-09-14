<?php

declare(strict_types=1);

namespace USDPAY\SDK;

use InvalidArgumentException;
use JsonException;
use Throwable;

final class UsdpayClient
{
    public const VERSION = '1.0.0';

    private string $secretKey;
    private string $baseUrl;
    private int $timeout;
    private int $connectTimeout;
    private TransportInterface $transport;

    public function __construct(
        string $secretKey,
        string $baseUrl = 'https://usdpay.me',
        int $timeout = 10,
        int $connectTimeout = 5,
        ?TransportInterface $transport = null
    ) {
        if (trim($secretKey) === '') {
            throw new InvalidArgumentException('secretKey is required');
        }
        if (str_contains($secretKey, "\r") || str_contains($secretKey, "\n")) {
            throw new InvalidArgumentException('secretKey must not contain line breaks');
        }
        if ($timeout < 1) {
            throw new InvalidArgumentException('timeout must be at least 1 second');
        }
        if ($connectTimeout < 1) {
            throw new InvalidArgumentException('connectTimeout must be at least 1 second');
        }

        $this->secretKey = $secretKey;
        $this->baseUrl = self::normalizeBaseUrl($baseUrl);
        $this->timeout = $timeout;
        $this->connectTimeout = $connectTimeout;
        $this->transport = $transport ?? new CurlTransport();
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     * @throws JsonException
     * @throws UsdpayApiException
     */
    public function createInvoice(array $payload, ?string $idempotencyKey = null): array
    {
        if (!isset($payload['amount']) || !is_string($payload['amount']) || trim($payload['amount']) === '') {
            throw new InvalidArgumentException('amount must be a non-empty decimal string');
        }

        $headers = [];
        if ($idempotencyKey !== null) {
            self::validateIdempotencyKey($idempotencyKey);
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        return $this->request('POST', '/api/invoices', $body, true, $headers);
    }

    /**
     * @return array<string, mixed>
     * @throws UsdpayApiException
     */
    public function getInvoice(string $invoiceId): array
    {
        $invoiceId = trim($invoiceId);
        if ($invoiceId === '') {
            throw new InvalidArgumentException('invoiceId is required');
        }

        return $this->request(
            'GET',
            '/api/invoices/' . rawurlencode($invoiceId),
            null,
            false
        );
    }

    /**
     * @return array<string, mixed>
     * @throws UsdpayApiException
     */
    public function listInvoices(): array
    {
        return $this->request('GET', '/api/invoices', null, true);
    }

    /**
     * @param array<string, string> $extraHeaders
     * @return array<string, mixed>
     * @throws UsdpayApiException
     */
    private function request(
        string $method,
        string $path,
        ?string $body,
        bool $authenticated,
        array $extraHeaders = []
    ): array {
        $headers = [
            'Accept' => 'application/json',
            'User-Agent' => 'usdpay-php/' . self::VERSION,
        ];

        if ($authenticated) {
            $headers['Authorization'] = 'Bearer ' . $this->secretKey;
        }
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
        }

        $headers = array_merge($headers, $extraHeaders);

        try {
            $response = $this->transport->send(
                $method,
                $this->baseUrl . $path,
                $headers,
                $body,
                $this->timeout,
                $this->connectTimeout
            );
        } catch (Throwable) {
            throw new UsdpayApiException(
                'Could not reach USDPAY',
                apiCode: 'network_error'
            );
        }

        if ($response->status < 200 || $response->status >= 300) {
            throw $this->createHttpException($response);
        }

        if ($response->body === '') {
            return [];
        }

        try {
            $decoded = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new UsdpayApiException(
                'USDPAY returned invalid JSON',
                httpStatus: $response->status,
                apiCode: 'invalid_response',
                requestId: self::nullableHeader($response, 'X-Request-ID')
            );
        }

        if (!is_array($decoded)) {
            throw new UsdpayApiException(
                'USDPAY returned an invalid response',
                httpStatus: $response->status,
                apiCode: 'invalid_response',
                requestId: self::nullableHeader($response, 'X-Request-ID')
            );
        }

        return $decoded;
    }

    private function createHttpException(HttpResponse $response): UsdpayApiException
    {
        $decoded = null;
        if ($response->body !== '') {
            try {
                $candidate = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($candidate)) {
                    $decoded = $candidate;
                }
            } catch (JsonException) {
                // Preserve the HTTP failure even when its response body is malformed.
            }
        }

        $apiCode = 'request_failed';
        if (is_array($decoded) && isset($decoded['error']) && is_string($decoded['error'])) {
            $candidateCode = trim($decoded['error']);
            if ($candidateCode !== '' && !str_contains($candidateCode, $this->secretKey)) {
                $apiCode = $candidateCode;
            }
        }

        $requestId = self::nullableHeader($response, 'X-Request-ID');
        if (is_array($decoded) && isset($decoded['requestId']) && is_string($decoded['requestId'])) {
            $candidateRequestId = trim($decoded['requestId']);
            if ($candidateRequestId !== '' && !str_contains($candidateRequestId, $this->secretKey)) {
                $requestId = $candidateRequestId;
            }
        }

        if ($requestId !== null && str_contains($requestId, $this->secretKey)) {
            $requestId = null;
        }

        return new UsdpayApiException(
            sprintf('USDPAY request failed with HTTP %d (%s)', $response->status, $apiCode),
            httpStatus: $response->status,
            apiCode: $apiCode,
            details: $this->redactDetails($decoded),
            retryAfter: $this->parseRetryAfter($response->header('Retry-After')),
            requestId: $requestId
        );
    }

    private static function normalizeBaseUrl(string $baseUrl): string
    {
        $baseUrl = rtrim(trim($baseUrl), '/');
        $parts = parse_url($baseUrl);

        if (
            $baseUrl === ''
            || $parts === false
            || ($parts['scheme'] ?? null) !== 'https'
            || empty($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || (isset($parts['path']) && $parts['path'] !== '' && $parts['path'] !== '/')
        ) {
            throw new InvalidArgumentException('baseUrl must be an HTTPS origin');
        }

        return $baseUrl;
    }

    private static function validateIdempotencyKey(string $idempotencyKey): void
    {
        if (
            strlen($idempotencyKey) < 1
            || strlen($idempotencyKey) > 160
            || preg_match('/^[A-Za-z0-9._:-]+$/', $idempotencyKey) !== 1
        ) {
            throw new InvalidArgumentException(
                'idempotencyKey must contain 1-160 letters, digits, dots, underscores, colons or hyphens'
            );
        }
    }

    private static function nullableHeader(HttpResponse $response, string $name): ?string
    {
        $value = $response->header($name);
        if ($value === null || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    private function parseRetryAfter(?string $value): int|string|null
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $value = trim($value);

        if (ctype_digit($value)) {
            return (int) $value;
        }

        return str_replace($this->secretKey, '[REDACTED]', $value);
    }

    private function redactDetails(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && preg_match('/authorization|api.?key|secret|token|password/i', $key) === 1) {
            return '[REDACTED]';
        }

        if (is_string($value)) {
            return str_replace($this->secretKey, '[REDACTED]', $value);
        }

        if (!is_array($value)) {
            return $value;
        }

        $redacted = [];
        foreach ($value as $itemKey => $itemValue) {
            $redacted[$itemKey] = $this->redactDetails(
                $itemValue,
                is_string($itemKey) ? $itemKey : null
            );
        }

        return $redacted;
    }
}
