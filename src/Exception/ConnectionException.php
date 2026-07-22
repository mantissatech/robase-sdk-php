<?php

declare(strict_types=1);

namespace Robase\Exception;

use Throwable;

/** The request never completed — network failure, DNS error, or timeout. */
final class ConnectionException extends RobaseException
{
    public function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct('connection_error', $message, previous: $previous);
    }
}
