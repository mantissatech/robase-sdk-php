<?php

declare(strict_types=1);

namespace Robase\Model;

use DateTimeImmutable;
use Robase\OtpStatus;

/** The result of {@see \Robase\Otp::send()}. */
final class SendOtpResult
{
    /**
     * @param string    $id          Identifies the OTP; pass it to verify() and get().
     * @param string    $countryCode ISO 3166-1 alpha-2 code derived from the number.
     * @param int       $creditCost  Credits the send consumed.
     * @param OtpStatus $status      Pending on accept — delivery is asynchronous.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $phoneNumber,
        public readonly string $countryCode,
        public readonly int $creditCost,
        public readonly OtpStatus $status,
        public readonly int $codeLength,
        /** When the code stops being verifiable. */
        public readonly ?DateTimeImmutable $expiresAt,
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
            status: Hydrate::otpStatus($data, 'status'),
            codeLength: Hydrate::int($data, 'code_length'),
            expiresAt: Hydrate::dateTime($data, 'expires_at'),
            createdAt: Hydrate::dateTime($data, 'created_at'),
        );
    }
}
