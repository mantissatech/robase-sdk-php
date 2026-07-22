<?php

declare(strict_types=1);

namespace Robase\Model;

use DateTimeImmutable;
use Robase\MessageStatus;
use Robase\OtpStatus;

/**
 * Lenient readers for decoded JSON.
 *
 * A missing or unexpectedly-typed field yields a zero value rather than a
 * fatal error: a new server-side field or a null in an optional slot should
 * never break a caller that doesn't read it.
 *
 * @internal
 */
final class Hydrate
{
    /** @param array<string, mixed> $data */
    public static function string(array $data, string $key, string $default = ''): string
    {
        $value = $data[$key] ?? null;

        return is_string($value) ? $value : $default;
    }

    /** @param array<string, mixed> $data */
    public static function nullableString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @param array<string, mixed> $data */
    public static function int(array $data, string $key, int $default = 0): int
    {
        $value = $data[$key] ?? null;

        return is_int($value) ? $value : (is_numeric($value) ? (int) $value : $default);
    }

    /** @param array<string, mixed> $data */
    public static function nullableInt(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    /** @param array<string, mixed> $data */
    public static function bool(array $data, string $key): bool
    {
        return (bool) ($data[$key] ?? false);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>|null
     */
    public static function map(array $data, string $key): ?array
    {
        $value = $data[$key] ?? null;

        return is_array($value) ? $value : null;
    }

    /** @param array<string, mixed> $data */
    public static function dateTime(array $data, string $key): ?DateTimeImmutable
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    /** @param array<string, mixed> $data */
    public static function otpStatus(array $data, string $key): OtpStatus
    {
        $value = $data[$key] ?? null;

        return is_string($value)
            ? (OtpStatus::tryFrom($value) ?? OtpStatus::Pending)
            : OtpStatus::Pending;
    }

    /** @param array<string, mixed> $data */
    public static function messageStatus(array $data, string $key): MessageStatus
    {
        $value = $data[$key] ?? null;

        return is_string($value)
            ? (MessageStatus::tryFrom($value) ?? MessageStatus::Pending)
            : MessageStatus::Pending;
    }
}
