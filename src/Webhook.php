<?php

declare(strict_types=1);

namespace Robase;

use DateTimeImmutable;
use Robase\Exception\SignatureVerificationException;
use Robase\Exception\ValidationException;

/**
 * Verifies and parses incoming webhook deliveries.
 *
 * Robase signs every delivery with X-Robase-Signature — a hex HMAC-SHA256 of
 * the raw body under your webhook secret.
 */
final class Webhook
{
    /** Header carrying the signature. */
    public const SIGNATURE_HEADER = 'X-Robase-Signature';

    /** Header carrying the event name, for routing before parsing. */
    public const EVENT_HEADER = 'X-Robase-Event';

    /**
     * Computes the signature Robase sends for a payload. Exported mainly for
     * building test fixtures.
     */
    public static function sign(string $payload, string $secret): string
    {
        return hash_hmac('sha256', $payload, $secret);
    }

    /**
     * Reports whether $signature authenticates $payload under $secret, comparing
     * in constant time.
     *
     * Pass the raw request body — re-encoding the JSON changes the signature.
     * In most frameworks that means `file_get_contents('php://input')` rather
     * than a parsed body array.
     */
    public static function verify(string $payload, ?string $signature, string $secret): bool
    {
        if ($signature === null || $signature === '') {
            return false;
        }

        return hash_equals(self::sign($payload, $secret), $signature);
    }

    /**
     * Verifies the signature and decodes the payload in one step.
     *
     * ```php
     * $payload = file_get_contents('php://input');
     * $event = Robase\Webhook::parse($payload, $_SERVER['HTTP_X_ROBASE_SIGNATURE'] ?? null, $secret);
     *
     * match ($event->event) {
     *     Robase\WebhookEvent::OtpVerified => handleVerified($event->data),
     *     default => null,
     * };
     * ```
     *
     * @throws SignatureVerificationException When the signature does not match.
     * @throws ValidationException            When the body is not a JSON object.
     */
    public static function parse(string $payload, ?string $signature, string $secret): WebhookPayload
    {
        if (!self::verify($payload, $signature, $secret)) {
            throw new SignatureVerificationException();
        }

        /** @var mixed $decoded */
        $decoded = json_decode($payload, true);
        if (!is_array($decoded)) {
            throw new ValidationException(
                'validation_error',
                'Webhook payload is not valid JSON',
                body: $payload,
            );
        }

        /** @var array<string, mixed> $decoded */
        $rawEvent = is_string($decoded['event'] ?? null) ? $decoded['event'] : '';
        $event = WebhookEvent::tryFrom($rawEvent);
        if ($event === null) {
            throw new ValidationException(
                'validation_error',
                sprintf('Unknown webhook event "%s"', $rawEvent),
                body: $payload,
            );
        }

        $timestamp = null;
        if (is_string($decoded['timestamp'] ?? null)) {
            try {
                $timestamp = new DateTimeImmutable($decoded['timestamp']);
            } catch (\Exception) {
                $timestamp = null;
            }
        }

        /** @var array<string, mixed> $data */
        $data = is_array($decoded['data'] ?? null) ? $decoded['data'] : [];

        return new WebhookPayload($event, $timestamp, $data);
    }
}
