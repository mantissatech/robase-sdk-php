<?php

declare(strict_types=1);

namespace Robase;

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
use Robase\Http\CurlTransport;
use Robase\Http\HttpResponse;
use Robase\Http\Transport;

/**
 * Client for the Robase SMS OTP API.
 *
 * ```php
 * $robase = new Robase\Client(getenv('ROBASE_API_KEY'));
 * $sent = $robase->otp->send('+2348012345678');
 * ```
 */
final class Client
{
    /** SDK version, reported in the User-Agent. */
    public const VERSION = '1.0.0';

    /** Production API endpoint. */
    public const DEFAULT_BASE_URL = 'https://api.robase.dev';

    private const MAX_RETRY_BACKOFF_MS = 8000;

    /** The /v1/otp endpoints. */
    public readonly Otp $otp;

    /** The /v1/sms endpoints. */
    public readonly Sms $sms;

    private readonly string $baseUrl;
    private readonly Transport $transport;

    /** @var array<string, string> */
    private readonly array $extraHeaders;

    /**
     * @param string                $apiKey       Key from the dashboard; starts with "robe_".
     * @param string|null           $baseUrl      Defaults to {@see DEFAULT_BASE_URL}.
     * @param float                 $timeout      Per-request timeout in seconds.
     * @param int                   $maxRetries   Retries for network errors, 429s and 5xxs. 0 disables.
     * @param Transport|null        $transport    Defaults to {@see CurlTransport}.
     * @param array<string, string> $extraHeaders Sent with every request.
     *
     * @throws ValidationException When the API key is missing or malformed.
     */
    public function __construct(
        private readonly string $apiKey,
        ?string $baseUrl = null,
        private readonly float $timeout = 30.0,
        private readonly int $maxRetries = 2,
        ?Transport $transport = null,
        array $extraHeaders = [],
    ) {
        if ($apiKey === '') {
            throw new ValidationException(
                'validation_error',
                'Missing API key. Pass it to the constructor or read it from ROBASE_API_KEY.',
            );
        }
        if (!str_starts_with($apiKey, 'robe_')) {
            throw new ValidationException(
                'validation_error',
                'Invalid API key: Robase keys start with "robe_".',
            );
        }

        $this->baseUrl = rtrim($baseUrl ?? self::DEFAULT_BASE_URL, '/');
        $this->transport = $transport ?? new CurlTransport();
        $this->extraHeaders = $extraHeaders;

        $this->otp = new Otp($this);
        $this->sms = new Sms($this);
    }

    /**
     * Issues an API call and returns the decoded body.
     *
     * @param 'GET'|'POST'              $method
     * @param array<string, mixed>|null $body
     * @param array<string, mixed>      $options Supported keys: idempotency_key (string),
     *                                           headers (array<string,string>), max_retries (int).
     *
     * @return array<string, mixed>
     *
     * @throws RobaseException
     *
     * @internal Use the $otp and $sms resources.
     */
    public function request(string $method, string $path, ?array $body = null, array $options = []): array
    {
        $payload = $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        // A POST that fails mid-flight may already have been charged. Giving
        // every POST an idempotency key makes the retry below safe: the server
        // replays the original response instead of sending a second message.
        $idempotencyKey = $options['idempotency_key'] ?? ($method === 'POST' ? self::uuid4() : null);

        $headers = [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Accept' => 'application/json',
            'User-Agent' => 'robase-php/' . self::VERSION,
        ];
        if ($payload !== null) {
            $headers['Content-Type'] = 'application/json';
        }
        if (is_string($idempotencyKey) && $idempotencyKey !== '') {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }
        /** @var array<string, string> $perRequestHeaders */
        $perRequestHeaders = is_array($options['headers'] ?? null) ? $options['headers'] : [];
        $headers = array_merge($headers, $this->extraHeaders, $perRequestHeaders);

        $budget = is_int($options['max_retries'] ?? null) ? $options['max_retries'] : $this->maxRetries;
        $url = $this->baseUrl . $path;

        for ($attempt = 0; ; $attempt++) {
            try {
                $response = $this->transport->send($method, $url, $headers, $payload, $this->timeout);
            } catch (ConnectionException $error) {
                if ($attempt >= $budget) {
                    throw $error;
                }
                self::sleepMs(self::backoffMs($attempt, null));

                continue;
            }

            if ($response->isSuccessful()) {
                return self::decode($response);
            }

            $error = self::errorFor($response);
            if ($attempt < $budget && self::isRetryable($response->status)) {
                self::sleepMs(self::backoffMs($attempt, $error->getRetryAfter()));

                continue;
            }

            throw $error;
        }
    }

    /**
     * @return array<string, mixed>
     *
     * @throws RobaseException
     */
    private static function decode(HttpResponse $response): array
    {
        if (trim($response->body) === '') {
            return [];
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new RobaseException(
                'api_error',
                'Failed to decode API response as JSON',
                $response->status,
                body: $response->body,
                previous: $error,
            );
        }

        if (!is_array($decoded)) {
            throw new RobaseException(
                'api_error',
                'Expected a JSON object from the API',
                $response->status,
                body: $response->body,
            );
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * Builds the right exception subclass from a non-2xx response.
     *
     * The API reports failures as {"error":{"type","message"}}, except the
     * anti-spam block, which sends {"error":"spam_detected","detail":{...}}.
     * Both shapes are handled, as is a body that isn't JSON at all.
     */
    private static function errorFor(HttpResponse $response): RobaseException
    {
        $type = null;
        $message = null;
        $detail = null;

        /** @var mixed $decoded */
        $decoded = json_decode($response->body, true);
        if (is_array($decoded)) {
            $detail = is_array($decoded['detail'] ?? null) ? $decoded['detail'] : null;
            /** @var mixed $envelope */
            $envelope = $decoded['error'] ?? null;

            if (is_string($envelope)) {
                $type = $envelope;
                $message = $envelope;
            } elseif (is_array($envelope)) {
                $type = is_string($envelope['type'] ?? null) ? $envelope['type'] : null;
                $message = is_string($envelope['message'] ?? null) ? $envelope['message'] : null;
            }
        }

        $status = $response->status;
        $type ??= self::fallbackType($status);
        $message ??= trim($response->body) !== '' ? trim($response->body) : 'HTTP ' . $status;

        $retryAfterHeader = $response->header('retry-after');
        $retryAfter = is_string($retryAfterHeader) && is_numeric($retryAfterHeader)
            ? (int) $retryAfterHeader
            : null;

        $class = match (true) {
            $status === 400 => ValidationException::class,
            $status === 401 => AuthenticationException::class,
            $status === 403 => $type === 'spam_detected'
                ? SpamDetectedException::class
                : AuthenticationException::class,
            $status === 402 => InsufficientCreditsException::class,
            $status === 404 => NotFoundException::class,
            $status === 409 => ConflictException::class,
            $status === 429 => RateLimitException::class,
            $status >= 500 => ServerException::class,
            default => RobaseException::class,
        };

        /** @var RobaseException */
        return new $class($type, $message, $status, $retryAfter, $detail, $response->body);
    }

    private static function fallbackType(int $status): string
    {
        return match (true) {
            $status === 401 => 'unauthorized',
            $status === 402 => 'insufficient_credits',
            $status === 429 => 'rate_limited',
            $status >= 500 => 'internal_error',
            default => 'api_error',
        };
    }

    private static function isRetryable(int $status): bool
    {
        return $status === 429 || $status >= 500;
    }

    /**
     * Delay before the next attempt: the server's Retry-After when it sent one,
     * otherwise exponential growth with full jitter so simultaneous callers
     * spread out.
     */
    private static function backoffMs(int $attempt, ?int $retryAfterSeconds): int
    {
        if ($retryAfterSeconds !== null && $retryAfterSeconds >= 0) {
            return min($retryAfterSeconds * 1000, self::MAX_RETRY_BACKOFF_MS);
        }

        $ceiling = min((int) (2 ** $attempt * 500), self::MAX_RETRY_BACKOFF_MS);

        return random_int(0, $ceiling);
    }

    private static function sleepMs(int $milliseconds): void
    {
        if ($milliseconds > 0) {
            usleep($milliseconds * 1000);
        }
    }

    /** Random RFC 4122 v4 UUID. */
    private static function uuid4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
