<?php

declare(strict_types=1);

namespace USDPAY\SDK\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use USDPAY\SDK\HttpResponse;
use USDPAY\SDK\UsdpayApiException;
use USDPAY\SDK\UsdpayClient;

final class UsdpayClientTest extends TestCase
{
    public function testPsr4AutoloadsPublicClasses(): void
    {
        self::assertTrue(class_exists(UsdpayClient::class));
        self::assertTrue(class_exists(UsdpayApiException::class));
    }

    public function testCreateInvoicePreservesRequestAndDecimalString(): void
    {
        $transport = new RecordingTransport(
            static fn (): HttpResponse => new HttpResponse(201, json_encode([
                'invoice' => [
                    'id' => 'inv_test',
                    'checkoutUrl' => 'https://example.test/pay/inv_test',
                ],
            ], JSON_THROW_ON_ERROR))
        );
        $client = new UsdpayClient(
            secretKey: 'sk_test_example',
            baseUrl: 'https://example.test/',
            timeout: 12,
            connectTimeout: 4,
            transport: $transport
        );

        $result = $client->createInvoice([
            'amount' => '49.00',
            'currency' => 'EUR',
            'orderId' => 'ORDER-1042',
            'network' => 'TRC20',
            'expiresInMinutes' => 30,
            'callbackUrl' => 'https://merchant.example/usdpay/webhook',
            'returnUrl' => 'https://merchant.example/orders/1042',
        ], 'ORDER-1042-create');

        self::assertSame('inv_test', $result['invoice']['id']);
        self::assertCount(1, $transport->requests);

        $request = $transport->requests[0];
        self::assertSame('POST', $request['method']);
        self::assertSame('https://example.test/api/invoices', $request['url']);
        self::assertSame('Bearer sk_test_example', $request['headers']['Authorization']);
        self::assertSame('application/json', $request['headers']['Content-Type']);
        self::assertSame('ORDER-1042-create', $request['headers']['Idempotency-Key']);
        self::assertSame('usdpay-php/1.0.0', $request['headers']['User-Agent']);
        self::assertSame(12, $request['timeout']);
        self::assertSame(4, $request['connectTimeout']);

        $body = json_decode($request['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('49.00', $body['amount']);
        self::assertIsString($body['amount']);
    }

    public function testGetInvoiceUsesPublicEncodedEndpointWithoutAuthorization(): void
    {
        $transport = new RecordingTransport(
            static fn (): HttpResponse => new HttpResponse(200, '{"invoice":{"id":"inv_a/b"}}')
        );
        $client = new UsdpayClient(secretKey: 'sk_test_example', transport: $transport);

        $result = $client->getInvoice('inv_a/b');

        self::assertSame('inv_a/b', $result['invoice']['id']);
        self::assertSame('GET', $transport->requests[0]['method']);
        self::assertSame('https://usdpay.me/api/invoices/inv_a%2Fb', $transport->requests[0]['url']);
        self::assertArrayNotHasKey('Authorization', $transport->requests[0]['headers']);
    }

    public function testListInvoicesUsesAuthenticatedEndpoint(): void
    {
        $transport = new RecordingTransport(
            static fn (): HttpResponse => new HttpResponse(200, '{"invoices":[]}')
        );
        $client = new UsdpayClient(secretKey: 'sk_test_example', transport: $transport);

        $result = $client->listInvoices();

        self::assertSame([], $result['invoices']);
        self::assertSame('https://usdpay.me/api/invoices', $transport->requests[0]['url']);
        self::assertSame('Bearer sk_test_example', $transport->requests[0]['headers']['Authorization']);
    }

    public function testUnauthorizedResponseBecomesRedactedStructuredException(): void
    {
        $secret = 'test_api_secret_value';
        $transport = new RecordingTransport(
            static fn (): HttpResponse => new HttpResponse(401, json_encode([
                'error' => 'unauthorized',
                'message' => 'Rejected test_api_secret_value',
                'authorization' => 'Bearer test_api_secret_value',
                'requestId' => 'req_401',
            ], JSON_THROW_ON_ERROR))
        );
        $client = new UsdpayClient(secretKey: $secret, transport: $transport);

        try {
            $client->createInvoice(['amount' => '10.00']);
            self::fail('Expected UsdpayApiException');
        } catch (UsdpayApiException $exception) {
            self::assertSame(401, $exception->httpStatus);
            self::assertSame(401, $exception->getHttpStatus());
            self::assertSame('unauthorized', $exception->apiCode);
            self::assertSame('unauthorized', $exception->getApiCode());
            self::assertSame('req_401', $exception->requestId);
            self::assertStringNotContainsString($secret, $exception->getMessage());
            self::assertStringNotContainsString(
                $secret,
                json_encode($exception->details, JSON_THROW_ON_ERROR)
            );
        }
    }

    public function testRateLimitExposesRetryAfterAndHeaderRequestId(): void
    {
        $transport = new RecordingTransport(
            static fn (): HttpResponse => new HttpResponse(
                429,
                '{"error":"rate_limited"}',
                ['Retry-After' => '60', 'X-Request-ID' => 'req_header']
            )
        );
        $client = new UsdpayClient(secretKey: 'sk_test_example', transport: $transport);

        try {
            $client->createInvoice(['amount' => '10.00'], 'stable-key');
            self::fail('Expected UsdpayApiException');
        } catch (UsdpayApiException $exception) {
            self::assertSame(429, $exception->getHttpStatus());
            self::assertSame('rate_limited', $exception->getApiCode());
            self::assertSame(60, $exception->getRetryAfter());
            self::assertSame('req_header', $exception->getRequestId());
            self::assertSame('stable-key', $transport->requests[0]['headers']['Idempotency-Key']);
        }
    }

    /**
     * @dataProvider otherHttpFailureProvider
     */
    public function testOtherDocumentedHttpFailuresRemainStructured(int $status): void
    {
        $transport = new RecordingTransport(
            static fn (): HttpResponse => new HttpResponse(
                $status,
                json_encode(['error' => 'status_' . $status], JSON_THROW_ON_ERROR)
            )
        );
        $client = new UsdpayClient(secretKey: 'sk_test_example', transport: $transport);

        try {
            $client->createInvoice(['amount' => '10.00']);
            self::fail('Expected UsdpayApiException');
        } catch (UsdpayApiException $exception) {
            self::assertSame($status, $exception->getHttpStatus());
            self::assertSame('status_' . $status, $exception->getApiCode());
        }
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function otherHttpFailureProvider(): iterable
    {
        yield 'bad request' => [400];
        yield 'billing blocked' => [402];
        yield 'conflict' => [409];
        yield 'server error' => [503];
    }

    public function testMalformedErrorBodyPreservesHttpFailure(): void
    {
        $transport = new RecordingTransport(
            static fn (): HttpResponse => new HttpResponse(503, '{not-json')
        );
        $client = new UsdpayClient(secretKey: 'sk_test_example', transport: $transport);

        try {
            $client->createInvoice(['amount' => '10.00']);
            self::fail('Expected UsdpayApiException');
        } catch (UsdpayApiException $exception) {
            self::assertSame(503, $exception->getHttpStatus());
            self::assertSame('request_failed', $exception->getApiCode());
        }
    }

    public function testMalformedSuccessfulJsonNeverSilentlyReturnsNull(): void
    {
        $transport = new RecordingTransport(
            static fn (): HttpResponse => new HttpResponse(200, '{not-json', ['X-Request-ID' => 'req_bad'])
        );
        $client = new UsdpayClient(secretKey: 'sk_test_example', transport: $transport);

        $this->expectException(UsdpayApiException::class);
        $this->expectExceptionMessage('USDPAY returned invalid JSON');

        $client->getInvoice('inv_test');
    }

    public function testNetworkErrorsDoNotExposeTransportOrSecretDetails(): void
    {
        $secret = 'test_api_secret_value';
        $transport = new RecordingTransport(
            static function () use ($secret): HttpResponse {
                throw new RuntimeException('Transport included ' . $secret);
            }
        );
        $client = new UsdpayClient(secretKey: $secret, transport: $transport);

        try {
            $client->createInvoice(['amount' => '10.00']);
            self::fail('Expected UsdpayApiException');
        } catch (UsdpayApiException $exception) {
            self::assertSame(0, $exception->getHttpStatus());
            self::assertSame('network_error', $exception->getApiCode());
            self::assertStringNotContainsString($secret, $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }
    }

    public function testAmountMustBeAStringAndBaseUrlMustUseHttps(): void
    {
        $transport = new RecordingTransport(
            static fn (): HttpResponse => new HttpResponse(201, '{}')
        );
        $client = new UsdpayClient(secretKey: 'sk_test_example', transport: $transport);

        try {
            $client->createInvoice(['amount' => 49.00]);
            self::fail('Expected an amount validation exception');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('decimal string', $exception->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        new UsdpayClient(secretKey: 'sk_test_example', baseUrl: 'http://example.test');
    }
}
