<?php

declare(strict_types=1);

namespace Robase\Exception;

/**
 * A rate limit tripped (HTTP 429).
 *
 * {@see RobaseException::getRetryAfter()} says how long to wait.
 */
final class RateLimitException extends RobaseException
{
}
