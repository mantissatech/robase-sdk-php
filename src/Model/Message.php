<?php

declare(strict_types=1);

namespace Robase\Model;

use DateTimeImmutable;
use Robase\MessageStatus;

/** The full transactional SMS record from {@see \Robase\Sms::get()}. */
final class Message
{
    /** @param array<string, mixed>|null $metadata */
    public function __construct(
        public readonly string $id,
        public readonly string $phoneNumber,
        public readonly string $countryCode,
        public readonly string $message,
        public readonly int $creditCost,
        public readonly MessageStatus $status,
        public readonly ?array $metadata,
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
            message: Hydrate::string($data, 'message'),
            creditCost: Hydrate::int($data, 'credit_cost'),
            status: Hydrate::messageStatus($data, 'status'),
            metadata: Hydrate::map($data, 'metadata'),
            createdAt: Hydrate::dateTime($data, 'created_at'),
        );
    }
}
