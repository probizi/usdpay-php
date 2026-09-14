# USDPAY PHP SDK

Official PHP SDK for USDPAY.

Accept USDT directly to your wallet. USDPAY verifies the payment on-chain and notifies your application automatically with signed webhooks.

This package is for trusted server-side PHP applications only.

## Requirements

- PHP 8.1 or newer
- PHP cURL extension
- A USDPAY project, store and secret API key

## Installation

```bash
composer require usdpay/sdk
```

## Quick Start

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use USDPAY\SDK\UsdpayClient;

$client = new UsdpayClient(
    secretKey: getenv('USDPAY_SECRET')
);

$result = $client->createInvoice([
    'amount' => '49.00',
    'orderId' => 'ORDER-1042',
    'network' => 'TRC20',
    'callbackUrl' => 'https://merchant.example/usdpay/webhook',
    'returnUrl' => 'https://merchant.example/orders/1042',
], 'ORDER-1042-create');

echo $result['invoice']['checkoutUrl'];
```

Keep `USDPAY_SECRET` outside your document root and never expose it in browser code, templates, logs or a public repository.

## Create an Invoice

Create invoices from your backend with decimal strings for monetary values:

```php
$result = $client->createInvoice([
    'amount' => '49.00',
    'orderId' => 'ORDER-1042',
    'network' => 'TRC20',
    'expiresInMinutes' => 30,
    'callbackUrl' => 'https://merchant.example/usdpay/webhook',
    'returnUrl' => 'https://merchant.example/orders/1042',
], 'ORDER-1042-create');

$invoice = $result['invoice'];
```

If `network` is omitted, the customer chooses an available network at hosted checkout. Use only the exact amount and payment address returned by the API.

## Get an Invoice

```php
$result = $client->getInvoice('inv_7Fq2xK9');
$invoice = $result['invoice'];
```

The current production contract exposes this unguessable-invoice-ID endpoint without Bearer authentication. The SDK therefore does not send the Authorization header for `getInvoice()`.

To list invoices belonging to the store selected by the secret key:

```php
$result = $client->listInvoices();
$invoices = $result['invoices'];
```

## Fiat Order Amounts

Pass the original order amount and ISO currency to USDPAY:

```php
$result = $client->createInvoice([
    'amount' => '49.00',
    'currency' => 'EUR',
    'orderId' => 'ORDER-1042',
    'callbackUrl' => 'https://merchant.example/usdpay/webhook',
]);
```

The SDK does not calculate exchange rates. USDPAY calculates the USDT amount and fixes that quote until the invoice expires. Monetary values remain strings; the SDK never converts them to PHP floats.

## Idempotency

Provide one stable `Idempotency-Key` for each create operation and reuse the same key when retrying that operation:

```php
$result = $client->createInvoice(
    ['amount' => '25.00', 'orderId' => 'ORDER-1043'],
    'ORDER-1043-create'
);
```

The SDK forwards the key unchanged and does not generate a replacement. Automatic HTTP retries are intentionally not performed in version 1.0.0. If your application retries a temporary failure, limit the number of attempts and keep the same key.

## Verify Webhooks

Always verify the signature against the unmodified request body before decoding JSON or fulfilling an order:

```php
<?php

use USDPAY\SDK\Webhook;

$rawBody = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_USDPAY_SIGNATURE'] ?? '';

if (!Webhook::verifySignature(
    $rawBody,
    $signature,
    getenv('USDPAY_WEBHOOK_SECRET')
)) {
    http_response_code(401);
    exit;
}

$event = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
```

Store `X-USDPAY-Idempotency-Key` under a unique constraint before fulfilling an order. The SDK verifies signatures but does not implement your application's database or fulfillment layer.

Respond with HTTP `2xx` promptly after safely recording the event. USDPAY may retry temporary webhook failures.

## Error Handling

API and transport failures are exposed as `UsdpayApiException`:

```php
use USDPAY\SDK\UsdpayApiException;

try {
    $result = $client->createInvoice(
        ['amount' => '49.00', 'orderId' => 'ORDER-1042'],
        'ORDER-1042-create'
    );
} catch (UsdpayApiException $exception) {
    $status = $exception->getHttpStatus();
    $apiCode = $exception->getApiCode();
    $details = $exception->getDetails();
    $retryAfter = $exception->getRetryAfter();
    $requestId = $exception->getRequestId();
}
```

Handle at least HTTP `400`, `401`, `402`, `409`, `429` and temporary `5xx` failures. `Retry-After` is available when the API supplies it. Exception messages never contain the Authorization header or API secret.

Malformed JSON responses raise an `invalid_response` exception instead of silently becoming `null`.

## Client Options

```php
$client = new UsdpayClient(
    secretKey: getenv('USDPAY_SECRET'),
    baseUrl: 'https://usdpay.me',
    timeout: 10,
    connectTimeout: 5
);
```

Only HTTPS base URLs are accepted. TLS peer and hostname verification are always enabled by the cURL transport.

## Security

- Use this SDK only in a trusted server-side environment.
- Never place secret API keys in frontend JavaScript, browser code, Twig or Blade templates.
- Never commit API keys or webhook secrets.
- Verify webhook signatures before parsing the body.
- Deduplicate webhook fulfillment in your database.
- Do not log Authorization headers or signing secrets.

The package stores no credentials, sends no telemetry or analytics, executes no shell commands and defines no Composer install/update scripts. USDPAY never needs a wallet seed phrase or private key.

## Documentation

- [Official website](https://usdpay.me/)
- [API documentation](https://usdpay.me/docs/payments)
- [PHP integration guide](https://usdpay.me/integrations/php)
- [Webhook documentation](https://usdpay.me/webhooks)
- [Security](https://usdpay.me/security)
- [Support](https://usdpay.me/contact)

## License

[MIT](LICENSE) © 2026 PIXELTIDE LLC.
