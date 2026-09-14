<?php

declare(strict_types=1);

namespace USDPAY\SDK\Tests;

use PHPUnit\Framework\TestCase;
use USDPAY\SDK\Webhook;

final class WebhookTest extends TestCase
{
    public function testValidWebhookHmacIsAccepted(): void
    {
        $rawBody = '{"event":"invoice.paid","invoice":{"id":"inv_test"}}';
        $secret = 'test_webhook_secret';
        $signature = 'sha256=' . hash_hmac('sha256', $rawBody, $secret);

        self::assertTrue(Webhook::verifySignature($rawBody, $signature, $secret));
    }

    public function testInvalidWebhookHmacIsRejected(): void
    {
        $rawBody = '{"event":"invoice.paid"}';
        $secret = 'test_webhook_secret';

        self::assertFalse(Webhook::verifySignature($rawBody, 'sha256=' . str_repeat('0', 64), $secret));
    }

    public function testModifiedRawBodyInvalidatesSignature(): void
    {
        $rawBody = '{"event":"invoice.paid"}';
        $secret = 'test_webhook_secret';
        $signature = 'sha256=' . hash_hmac('sha256', $rawBody, $secret);

        self::assertFalse(Webhook::verifySignature($rawBody . ' ', $signature, $secret));
    }
}
