<?php

declare(strict_types=1);

namespace USDPAY\SDK;

use RuntimeException;

final class CurlTransport implements TransportInterface
{
    public function send(
        string $method,
        string $url,
        array $headers,
        ?string $body,
        int $timeout,
        int $connectTimeout
    ): HttpResponse {
        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException('Could not initialize cURL');
        }

        $responseHeaders = [];
        $headerCallback = static function ($curl, string $line) use (&$responseHeaders): int {
            $length = strlen($line);
            $trimmed = trim($line);

            if (str_starts_with($trimmed, 'HTTP/')) {
                $responseHeaders = [];
                return $length;
            }

            $separator = strpos($line, ':');
            if ($separator !== false) {
                $name = strtolower(trim(substr($line, 0, $separator)));
                $value = trim(substr($line, $separator + 1));
                if ($name !== '') {
                    $responseHeaders[$name] = $value;
                }
            }

            return $length;
        };

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_HEADERFUNCTION => $headerCallback,
        ];

        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }

        try {
            if (!curl_setopt_array($handle, $options)) {
                throw new RuntimeException('Could not configure cURL');
            }

            $responseBody = curl_exec($handle);
            if ($responseBody === false) {
                throw new RuntimeException('cURL request failed: ' . curl_error($handle));
            }

            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

            return new HttpResponse($status, $responseBody, $responseHeaders);
        } finally {
            curl_close($handle);
        }
    }
}
