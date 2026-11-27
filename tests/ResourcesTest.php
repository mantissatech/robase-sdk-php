<?php

declare(strict_types=1);

namespace Robase\Tests;

use PHPUnit\Framework\TestCase;
use Robase\Client;
use Robase\Exception\ConflictException;
use Robase\Exception\NotFoundException;
use Robase\Exception\ValidationException;
use Robase\Http\HttpResponse;
use Robase\MessageStatus;
use Robase\Model\Otp as OtpModel;
use Robase\OtpStatus;
use Robase\Sms;
use Robase\Tests\Support\StubTransport;

final class ResourcesTest extends TestCase
{
    /** @param list<HttpResponse> $queue */
    private function client(array $queue): array
    {
        $transport = new StubTransport($queue);
        $client = new Client('robe_test_key', 'https://api.test.local', maxRetries: 0, transport: $transport);

        return [$client, $transport];
    }

    public function testOtpSendPostsToTheRightPathAndDecodesTheRecord(): void
    {
        [$client, $transport] = $this->client([
            StubTransport::json([
                'id' => '7f3a1c9e-0000-4000-8000-000000000001',
                'phone_number' => '+2348012345678',
                'country_code' => 'NG',
                'credit_cost' => 1,
                'status' => 'pending',
                'code_length' => 6,
                'expires_at' => '2026-07-22T10:10:00Z',
                'created_at' => '2026-07-22T10:00:00Z',
            ]),
        ]);

        $sent = $client->otp->send('+2348012345678');

        self::assertSame('POST', $transport->requests[0]['method']);
        self::assertSame('https://api.test.local/v1/otp/send', $transport->requests[0]['url']);
        self::assertSame('NG', $sent->countryCode);
        self::assertSame(OtpStatus::Pending, $sent->status);
        self::assertSame(1, $sent->creditCost);
        self::assertSame('2026-07-22T10:10:00+00:00', $sent->expiresAt?->format('c'));
    }

    public function testOtpSendOmitsUnsetOptionalsSoServerDefaultsApply(): void
    {
        [$client, $transport] = $this->client([StubTransport::json([])]);
        $client->otp->send('+2348012345678');

        self::assertSame(['phone_number' => '+2348012345678'], $transport->decodedBody());
    }

    public function testOtpSendIncludesOptionalsWhenGiven(): void
    {
        [$client, $transport] = $this->client([StubTransport::json([])]);
        $client->otp->send('+2348012345678', codeLength: 4, ttlSeconds: 300, metadata: ['user_id' => 'u_1']);

        self::assertSame([
            'phone_number' => '+2348012345678',
            'code_length' => 4,
            'ttl_seconds' => 300,
            'metadata' => ['user_id' => 'u_1'],
        ], $transport->decodedBody());
    }

    public function testOtpSendIncludesACallerSuppliedCodeWhenGiven(): void
    {
        [$client, $transport] = $this->client([StubTransport::json([])]);
        $client->otp->send('+2348012345678', code: '908172');

        self::assertSame([
            'phone_number' => '+2348012345678',
            'code' => '908172',
        ], $transport->decodedBody());
    }

    public function testOtpSendRejectsAnEmptyPhoneNumberWithoutCallingTheApi(): void
    {
        [$client, $transport] = $this->client([StubTransport::json([])]);

        $this->expectException(ValidationException::class);

        try {
            $client->otp->send('');
        } finally {
            self::assertSame(0, $transport->count());
        }
    }

    public function testOtpVerifySendsOtpIdNotId(): void
    {
        [$client, $transport] = $this->client([
            StubTransport::json(['valid' => true, 'status' => 'verified', 'attempts_used' => 1]),
        ]);

        $result = $client->otp->verify('otp_1', '123456');

        self::assertSame(['otp_id' => 'otp_1', 'code' => '123456'], $transport->decodedBody());
        self::assertTrue($result->valid);
        self::assertSame(OtpStatus::Verified, $result->status);
        self::assertNull($result->attemptsRemaining);
    }

    public function testOtpVerifyTreatsAWrongCodeAsASuccessfulCall(): void
    {
        [$client] = $this->client([
            StubTransport::json([
                'valid' => false,
                'status' => 'sent',
                'attempts_used' => 2,
                'attempts_remaining' => 3,
            ]),
        ]);

        $result = $client->otp->verify('otp_1', '000000');

        self::assertFalse($result->valid);
        self::assertSame(3, $result->attemptsRemaining);
    }

    public function testOtpVerifyThrowsOnTerminalStates(): void
    {
        [$client] = $this->client([
            StubTransport::json(['error' => ['type' => 'max_attempts_exceeded', 'message' => 'too many']], 409),
        ]);

        try {
            $client->otp->verify('otp_1', '000000');
            self::fail('expected ConflictException');
        } catch (ConflictException $error) {
            self::assertSame('max_attempts_exceeded', $error->getType());
        }
    }

    public function testOtpGetReturnsTheFullRecord(): void
    {
        [$client, $transport] = $this->client([
            StubTransport::json([
                'id' => 'otp_1',
                'workspace_id' => 'ws_1',
                'phone_number' => '+2348012345678',
                'country_code' => 'NG',
                'credit_cost' => 1,
                'provider' => 'beem',
                'status' => 'sent',
                'code_length' => 6,
                'ttl_seconds' => 600,
                'attempt_count' => 0,
                'max_attempts' => 5,
                'sent_at' => '2026-07-22T10:00:05Z',
                'expired_at' => '2026-07-22T10:10:00Z',
                'created_at' => '2026-07-22T10:00:00Z',
            ]),
        ]);

        $otp = $client->otp->get('otp_1');

        self::assertSame('https://api.test.local/v1/otp/otp_1', $transport->requests[0]['url']);
        self::assertSame('beem', $otp->provider);
        self::assertSame(5, $otp->maxAttempts);
        self::assertNotNull($otp->sentAt);
        self::assertNull($otp->deliveredAt);
    }

    public function testOtpIsExpiredComparesAgainstTheExpiryTimestamp(): void
    {
        $past = OtpModel::fromArray(['expired_at' => '2020-01-01T00:00:00Z']);
        $future = OtpModel::fromArray(['expired_at' => '2999-01-01T00:00:00Z']);
        $unknown = OtpModel::fromArray([]);

        self::assertTrue($past->isExpired());
        self::assertFalse($future->isExpired());
        self::assertFalse($unknown->isExpired());
    }

    public function testOtpGetThrowsNotFound(): void
    {
        [$client] = $this->client([
            StubTransport::json(['error' => ['type' => 'otp_not_found', 'message' => 'OTP not found']], 404),
        ]);

        $this->expectException(NotFoundException::class);
        $client->otp->get('missing');
    }

    public function testSmsSendPostsToTheRightPath(): void
    {
        [$client, $transport] = $this->client([
            StubTransport::json([
                'id' => 'msg_1',
                'phone_number' => '+2348012345678',
                'country_code' => 'NG',
                'credit_cost' => 1,
                'status' => 'pending',
                'created_at' => '2026-07-22T10:00:00Z',
            ]),
        ]);

        $msg = $client->sms->send('+2348012345678', 'Your order #12345 has shipped.');

        self::assertSame('https://api.test.local/v1/sms/send', $transport->requests[0]['url']);
        self::assertSame('msg_1', $msg->id);
        self::assertSame(MessageStatus::Pending, $msg->status);
    }

    public function testSmsSendValidatesLocallyBeforeSpendingARequest(): void
    {
        [$client, $transport] = $this->client([StubTransport::json([])]);

        $rejected = 0;
        foreach ([['', 'hi'], ['+2348012345678', ''], ['+2348012345678', str_repeat('a', Sms::MAX_MESSAGE_LENGTH + 1)]] as [$phone, $message]) {
            try {
                $client->sms->send($phone, $message);
            } catch (ValidationException) {
                $rejected++;
            }
        }

        self::assertSame(3, $rejected);
        self::assertSame(0, $transport->count());
    }

    public function testSmsGetReturnsTheMessageWithMetadata(): void
    {
        [$client] = $this->client([
            StubTransport::json([
                'id' => 'msg_1',
                'phone_number' => '+2348012345678',
                'country_code' => 'NG',
                'credit_cost' => 1,
                'status' => 'delivered',
                'message' => 'Your order #12345 has shipped.',
                'metadata' => ['order_id' => '12345'],
                'created_at' => '2026-07-22T10:00:00Z',
            ]),
        ]);

        $msg = $client->sms->get('msg_1');

        self::assertSame(MessageStatus::Delivered, $msg->status);
        self::assertSame('12345', $msg->metadata['order_id'] ?? null);
    }

    public function testSmsGetThrowsNotFound(): void
    {
        [$client] = $this->client([
            StubTransport::json(['error' => ['type' => 'sms_not_found', 'message' => 'SMS message not found']], 404),
        ]);

        $this->expectException(NotFoundException::class);
        $client->sms->get('missing');
    }
}
