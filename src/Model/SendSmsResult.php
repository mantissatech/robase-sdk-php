<?php

declare(strict_types=1);

namespace Robase\Model;

use DateTimeImmutable;
use Robase\MessageStatus;

/** The result of {@see \Robase\Sms::send()}. */
final class SendSmsResult
{
    public function __construct(
        public readonly string $id,
        public readonly string $phoneNumber,
        public readonly string $countryCode,
        public readonly int $creditCost,
        /** Always pending here; it transitions asynchronously. */
        public readonly MessageStatus $status,
        public readonly ?DateTimeImmutable $createdAt,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            id: Hydrate::string($data, 'id'),
            phoneNumber: Hydrate::string($data, 'phone_number'),
            countryCode: Hydrate::string($data, 'country_code'),
            creditCost: Hydrate::int($data, 'credit_cost'),
            status: Hydrate::messageStatus($data, 'status'),
            createdAt: Hydrate::dateTime($data, 'created_at'),
        );
    }
}
