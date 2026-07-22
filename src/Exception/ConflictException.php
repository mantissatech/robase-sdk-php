<?php

declare(strict_types=1);

namespace Robase\Exception;

/**
 * The OTP is in a terminal state (HTTP 409) — expired, already verified, or out
 * of attempts. Check {@see RobaseException::getType()} to tell them apart.
 */
final class ConflictException extends RobaseException
{
}
