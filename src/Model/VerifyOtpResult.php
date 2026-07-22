<?php

declare(strict_types=1);

namespace Robase\Model;

use Robase\OtpStatus;

/**
 * The result of {@see \Robase\Otp::verify()}.
 *
 * A wrong-but-still-usable code is not an exception: the call returns with
 * {@see $valid} false and {@see $attemptsRemaining} set. Terminal states —
 * expired, already verified, attempts exhausted — throw a ConflictException.
 */
final class VerifyOtpResult
{
    /**
     * @param bool     $valid             Whether the submitted code matched.
     * @param int      $attemptsUsed      Attempts made so far, including this one.
     * @param int|null $attemptsRemaining Attempts left; only sent when $valid is false.
     */
    public function __construct(
        public readonly bool $valid,
        public readonly OtpStatus $status,
        public readonly int $attemptsUsed,
        public readonly ?int $attemptsRemaining = null,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            valid: Hydrate::bool($data, 'valid'),
            status: Hydrate::otpStatus($data, 'status'),
            attemptsUsed: Hydrate::int($data, 'attempts_used'),
            attemptsRemaining: Hydrate::nullableInt($data, 'attempts_remaining'),
        );
    }
}
