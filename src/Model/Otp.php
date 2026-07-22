<?php

declare(strict_types=1);

namespace Robase\Model;

use DateTimeImmutable;
use Robase\OtpStatus;

/** The full OTP record from {@see \Robase\Otp::get()}. The code is never returned. */
final class Otp
{
    /** @param array<string, mixed>|null $metadata */
    public function __construct(
        public readonly string $id,
        public readonly string $workspaceId,
        public readonly string $phoneNumber,
        public readonly string $countryCode,
        public readonly int $creditCost,
        public readonly ?string $provider,
        public readonly ?string $providerMessageId,
        public readonly OtpStatus $status,
        public readonly int $codeLength,
        public readonly int $ttlSeconds,
        public readonly int $attemptCount,
        public readonly int $maxAttempts,
        public readonly ?DateTimeImmutable $sentAt,
        public readonly ?DateTimeImmutable $deliveredAt,
        public readonly ?DateTimeImmutable $verifiedAt,
        public readonly ?DateTimeImmutable $expiredAt,
        public readonly ?array $metadata,
        public readonly ?DateTimeImmutable $createdAt,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            id: Hydrate::string($data, 'id'),
            workspaceId: Hydrate::string($data, 'workspace_id'),
            phoneNumber: Hydrate::string($data, 'phone_number'),
            countryCode: Hydrate::string($data, 'country_code'),
            creditCost: Hydrate::int($data, 'credit_cost'),
            provider: Hydrate::nullableString($data, 'provider'),
            providerMessageId: Hydrate::nullableString($data, 'provider_msg_id'),
            status: Hydrate::otpStatus($data, 'status'),
            codeLength: Hydrate::int($data, 'code_length'),
            ttlSeconds: Hydrate::int($data, 'ttl_seconds'),
            attemptCount: Hydrate::int($data, 'attempt_count'),
            maxAttempts: Hydrate::int($data, 'max_attempts'),
            sentAt: Hydrate::dateTime($data, 'sent_at'),
            deliveredAt: Hydrate::dateTime($data, 'delivered_at'),
            verifiedAt: Hydrate::dateTime($data, 'verified_at'),
            expiredAt: Hydrate::dateTime($data, 'expired_at'),
            metadata: Hydrate::map($data, 'metadata'),
            createdAt: Hydrate::dateTime($data, 'created_at'),
        );
    }

    /** Whether the OTP's TTL has already elapsed. */
    public function isExpired(): bool
    {
        return $this->expiredAt !== null && $this->expiredAt < new DateTimeImmutable();
    }
}
