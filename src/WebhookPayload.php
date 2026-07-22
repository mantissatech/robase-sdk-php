<?php

declare(strict_types=1);

namespace Robase;

use DateTimeImmutable;

/** A verified webhook delivery, as returned by {@see Webhook::parse()}. */
final class WebhookPayload
{
    /**
     * @param array<string, mixed> $data Event-specific payload. Its shape follows
     *                                   the event: OTP events carry id, phone_number,
     *                                   country_code, status, provider and credit_cost;
     *                                   SMS events add message; credit events carry
     *                                   workspace_id, balance and threshold.
     */
    public function __construct(
        public readonly WebhookEvent $event,
        public readonly ?DateTimeImmutable $timestamp,
        public readonly array $data,
    ) {
    }

    /** True for otp.* events. */
    public function isOtpEvent(): bool
    {
        return str_starts_with($this->event->value, 'otp.');
    }

    /** True for sms.* events. */
    public function isSmsEvent(): bool
    {
        return str_starts_with($this->event->value, 'sms.');
    }

    /** True for credit_balance.* events. */
    public function isCreditEvent(): bool
    {
        return str_starts_with($this->event->value, 'credit_balance.');
    }

    /** Reads a string field from the payload data. */
    public function string(string $key): ?string
    {
        $value = $this->data[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    /** Reads an integer field from the payload data. */
    public function int(string $key): ?int
    {
        $value = $this->data[$key] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }
}
