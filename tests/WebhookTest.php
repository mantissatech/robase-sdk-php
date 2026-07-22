<?php

declare(strict_types=1);

namespace Robase\Tests;

use PHPUnit\Framework\TestCase;
use Robase\Exception\SignatureVerificationException;
use Robase\Exception\ValidationException;
use Robase\Webhook;
use Robase\WebhookEvent;

final class WebhookTest extends TestCase
{
    private const SECRET = 'whsec_test';

    /**
     * Must match the Go server's core.SignWebhookPayload byte for byte:
     * lowercase hex HMAC-SHA256 over the raw body.
     */
    public function testSignatureMatchesTheServerFixture(): void
    {
        self::assertSame(
            '88aab3ede8d3adf94d26ab90d3bafd4a2083070c3bcce9c014ee04a443847c0b',
            Webhook::sign('hello', 'secret'),
        );
    }

    public function testVerifyAcceptsACorrectSignature(): void
    {
        $body = '{"event":"otp.sent","timestamp":"2026-07-22T10:00:00Z","data":{}}';

        self::assertTrue(Webhook::verify($body, Webhook::sign($body, self::SECRET), self::SECRET));
    }

    public function testVerifyRejectsTheWrongSecret(): void
    {
        $body = '{"event":"otp.sent","timestamp":"2026-07-22T10:00:00Z","data":{}}';

        self::assertFalse(Webhook::verify($body, Webhook::sign($body, self::SECRET), 'other'));
    }

    public function testVerifyRejectsATamperedBody(): void
    {
        $body = '{"event":"otp.sent","timestamp":"2026-07-22T10:00:00Z","data":{}}';
        $signature = Webhook::sign($body, self::SECRET);

        self::assertFalse(Webhook::verify($body . ' ', $signature, self::SECRET));
    }

    public function testVerifyRejectsAMissingSignature(): void
    {
        self::assertFalse(Webhook::verify('{}', null, self::SECRET));
        self::assertFalse(Webhook::verify('{}', '', self::SECRET));
    }

    public function testParseReturnsAnOtpEvent(): void
    {
        $body = json_encode([
            'event' => 'otp.verified',
            'timestamp' => '2026-07-22T10:00:00Z',
            'data' => [
                'id' => 'otp_1',
                'phone_number' => '+2348012345678',
                'country_code' => 'NG',
                'status' => 'verified',
                'provider' => 'beem',
                'credit_cost' => 1,
            ],
        ], JSON_THROW_ON_ERROR);

        $event = Webhook::parse($body, Webhook::sign($body, self::SECRET), self::SECRET);

        self::assertSame(WebhookEvent::OtpVerified, $event->event);
        self::assertTrue($event->isOtpEvent());
        self::assertFalse($event->isSmsEvent());
        self::assertSame('otp_1', $event->string('id'));
        self::assertSame(1, $event->int('credit_cost'));
        self::assertSame('2026-07-22T10:00:00+00:00', $event->timestamp?->format('c'));
    }

    public function testParseReturnsAnSmsEvent(): void
    {
        $body = json_encode([
            'event' => 'sms.delivered',
            'timestamp' => '2026-07-22T10:00:00Z',
            'data' => ['id' => 'msg_1', 'message' => 'hi', 'status' => 'delivered'],
        ], JSON_THROW_ON_ERROR);

        $event = Webhook::parse($body, Webhook::sign($body, self::SECRET), self::SECRET);

        self::assertSame(WebhookEvent::SmsDelivered, $event->event);
        self::assertTrue($event->isSmsEvent());
        self::assertSame('hi', $event->string('message'));
    }

    public function testParseReturnsACreditEvent(): void
    {
        $body = json_encode([
            'event' => 'credit_balance.low',
            'timestamp' => '2026-07-22T10:00:00Z',
            'data' => ['workspace_id' => 'ws_1', 'balance' => 42, 'threshold' => 100],
        ], JSON_THROW_ON_ERROR);

        $event = Webhook::parse($body, Webhook::sign($body, self::SECRET), self::SECRET);

        self::assertTrue($event->isCreditEvent());
        self::assertSame(42, $event->int('balance'));
        self::assertSame(100, $event->int('threshold'));
    }

    public function testParseThrowsOnABadSignature(): void
    {
        $this->expectException(SignatureVerificationException::class);
        Webhook::parse('{"event":"otp.sent","timestamp":"","data":{}}', 'deadbeef', self::SECRET);
    }

    public function testParseThrowsOnAnUnknownEvent(): void
    {
        $body = '{"event":"otp.teleported","timestamp":"","data":{}}';

        $this->expectException(ValidationException::class);
        Webhook::parse($body, Webhook::sign($body, self::SECRET), self::SECRET);
    }
}
