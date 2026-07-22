<?php

declare(strict_types=1);

namespace Robase\Tests;

use PHPUnit\Framework\TestCase;
use Robase\Client;
use Robase\Exception\AuthenticationException;
use Robase\Exception\ConflictException;
use Robase\Exception\ConnectionException;
use Robase\Exception\InsufficientCreditsException;
use Robase\Exception\NotFoundException;
use Robase\Exception\RateLimitException;
use Robase\Exception\RobaseException;
use Robase\Exception\ServerException;
use Robase\Exception\SpamDetectedException;
use Robase\Exception\ValidationException;
use Robase\Http\HttpResponse;
use Robase\Tests\Support\StubTransport;

final class ClientTest extends TestCase
{
    /** @param list<HttpResponse|ConnectionException> $queue */
    private function client(array $queue, int $maxRetries = 0): array
    {
        $transport = new StubTransport($queue);
        $client = new Client(
            apiKey: 'robe_test_key',
            baseUrl: 'https://api.test.local',
            timeout: 5.0,
            maxRetries: $maxRetries,
            transport: $transport,
        );

        return [$client, $transport];
    }

    public function testRejectsAnEmptyApiKey(): void
    {
        $this->expectException(ValidationException::class);
        new Client('');
    }

    public function testRejectsAKeyWithoutTheRobePrefix(): void
    {
        $this->expectException(ValidationException::class);
        new Client('sk_live_nope');
    }

    public function testSendsAuthAndContentHeaders(): void
    {
        [$client, $transport] = $this->client([StubTransport::json(['id' => 'otp_1'])]);
        $client->otp->send('+2348012345678');

        self::assertSame('Bearer robe_test_key', $transport->header('authorization'));
        self::assertSame('application/json', $transport->header('content-type'));
        self::assertStringStartsWith('robase-php/', (string) $transport->header('user-agent'));
    }

    public function testAutoGeneratesAnIdempotencyKeyOnPost(): void
    {
        [$client, $transport] = $this->client([StubTransport::json([])]);
        $client->otp->send('+2348012345678');

        self::assertNotNull($transport->header('idempotency-key'));
    }

    public function testDoesNotSendAnIdempotencyKeyOnGet(): void
    {
        [$client, $transport] = $this->client([StubTransport::json([])]);
        $client->otp->get('otp_1');

        self::assertNull($transport->header('idempotency-key'));
    }

    public function testHonoursAnExplicitIdempotencyKey(): void
    {
        [$client, $transport] = $this->client([StubTransport::json([])]);
        $client->sms->send('+2348012345678', 'hi', options: ['idempotency_key' => 'order-42']);

        self::assertSame('order-42', $transport->header('idempotency-key'));
    }

    /**
     * @return list<array{int, array<string, mixed>, class-string<RobaseException>, string}>
     */
    public static function errorProvider(): array
    {
        return [
            [400, ['error' => ['type' => 'validation_error', 'message' => 'bad']], ValidationException::class, 'validation_error'],
            [401, ['error' => ['type' => 'unauthorized', 'message' => 'nope']], AuthenticationException::class, 'unauthorized'],
            [402, ['error' => ['type' => 'insufficient_credits', 'message' => 'broke']], InsufficientCreditsException::class, 'insufficient_credits'],
            [404, ['error' => ['type' => 'otp_not_found', 'message' => 'gone']], NotFoundException::class, 'otp_not_found'],
            [409, ['error' => ['type' => 'otp_expired', 'message' => 'expired']], ConflictException::class, 'otp_expired'],
            [429, ['error' => ['type' => 'rate_limited', 'message' => 'slow']], RateLimitException::class, 'rate_limited'],
            [500, ['error' => ['type' => 'internal_error', 'message' => 'boom']], ServerException::class, 'internal_error'],
        ];
    }

    /**
     * @param array<string, mixed>          $body
     * @param class-string<RobaseException> $expected
     *
     * @dataProvider errorProvider
     */
    public function testMapsErrorEnvelopesToExceptions(
        int $status,
        array $body,
        string $expected,
        string $type,
    ): void {
        [$client] = $this->client([StubTransport::json($body, $status)]);

        try {
            $client->otp->send('+2348012345678');
            self::fail('expected ' . $expected);
        } catch (RobaseException $error) {
            self::assertInstanceOf($expected, $error);
            self::assertSame($type, $error->getType());
            self::assertSame($status, $error->getStatus());
        }
    }

    public function testMapsTheAntiSpamBlockAndKeepsItsDetail(): void
    {
        [$client] = $this->client([
            StubTransport::json([
                'error' => 'spam_detected',
                'detail' => ['score' => 0.94, 'category' => 'phishing', 'reason' => 'credential harvesting'],
            ], 403),
        ]);

        try {
            $client->sms->send('+2348012345678', 'click here');
            self::fail('expected SpamDetectedException');
        } catch (SpamDetectedException $error) {
            self::assertSame('spam_detected', $error->getType());
            self::assertSame('phishing', $error->getDetail()['category'] ?? null);
        }
    }

    public function testClassifiesANonJsonErrorBodyByStatus(): void
    {
        [$client] = $this->client([new HttpResponse(502, '<html>bad gateway</html>')]);

        try {
            $client->otp->get('otp_1');
            self::fail('expected ServerException');
        } catch (ServerException $error) {
            self::assertSame('internal_error', $error->getType());
        }
    }

    public function testSurfacesRetryAfter(): void
    {
        [$client] = $this->client([
            StubTransport::json(
                ['error' => ['type' => 'rate_limited', 'message' => 'slow']],
                429,
                ['retry-after' => '30'],
            ),
        ]);

        try {
            $client->otp->send('+2348012345678');
            self::fail('expected RateLimitException');
        } catch (RateLimitException $error) {
            self::assertSame(30, $error->getRetryAfter());
        }
    }

    public function testRetriesA429ThenSucceeds(): void
    {
        [$client, $transport] = $this->client([
            StubTransport::json(['error' => ['type' => 'rate_limited', 'message' => 'slow']], 429, ['retry-after' => '0']),
            StubTransport::json(['id' => 'otp_1']),
        ], maxRetries: 2);

        $sent = $client->otp->send('+2348012345678');

        self::assertSame('otp_1', $sent->id);
        self::assertSame(2, $transport->count());
    }

    public function testReusesTheSameIdempotencyKeyAcrossRetries(): void
    {
        [$client, $transport] = $this->client([
            StubTransport::json(['error' => ['type' => 'internal_error', 'message' => 'boom']], 500, ['retry-after' => '0']),
            StubTransport::json(['id' => 'otp_1']),
        ], maxRetries: 1);

        $client->otp->send('+2348012345678');

        self::assertSame(2, $transport->count());
        self::assertNotNull($transport->header('idempotency-key', 0));
        self::assertSame($transport->header('idempotency-key', 0), $transport->header('idempotency-key', 1));
    }

    public function testDoesNotRetryClientErrors(): void
    {
        [$client, $transport] = $this->client([
            StubTransport::json(['error' => ['type' => 'invalid_phone', 'message' => 'bad']], 400),
        ], maxRetries: 3);

        try {
            $client->otp->send('+234');
        } catch (ValidationException) {
            // expected
        }

        self::assertSame(1, $transport->count());
    }

    public function testRetriesTransportFailuresThenGivesUp(): void
    {
        [$client, $transport] = $this->client([
            new ConnectionException('network down'),
        ], maxRetries: 2);

        $this->expectException(ConnectionException::class);

        try {
            $client->otp->get('otp_1');
        } finally {
            self::assertSame(3, $transport->count());
        }
    }

    public function testHonoursAPerRequestRetryOverride(): void
    {
        [$client, $transport] = $this->client([
            StubTransport::json([], 503, ['retry-after' => '0']),
        ], maxRetries: 5);

        try {
            $client->otp->get('otp_1', ['max_retries' => 0]);
        } catch (ServerException) {
            // expected
        }

        self::assertSame(1, $transport->count());
    }

    public function testStripsTrailingSlashesFromTheBaseUrl(): void
    {
        $transport = new StubTransport([StubTransport::json([])]);
        $client = new Client('robe_x', 'http://localhost:8080///', transport: $transport);
        $client->sms->get('msg_1');

        self::assertSame('http://localhost:8080/v1/sms/msg_1', $transport->requests[0]['url']);
    }
}
