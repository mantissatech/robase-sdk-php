<?php

declare(strict_types=1);

namespace Robase\Exception;

use RuntimeException;
use Throwable;

/**
 * Base class for every exception this SDK throws.
 *
 * Catch a subclass for the common cases, or inspect {@see getType()} when you
 * need to tell apart the several error types that share an HTTP status.
 */
class RobaseException extends RuntimeException
{
    /**
     * @param string                    $type       The API's error identifier, e.g. "insufficient_credits".
     * @param string                    $message    Human-readable description.
     * @param int|null                  $status     HTTP status, or null when no response arrived.
     * @param int|null                  $retryAfter Seconds from the Retry-After header.
     * @param array<string, mixed>|null $detail     Extra context the endpoint attached.
     * @param string|null               $body       Raw response body.
     */
    public function __construct(
        private readonly string $type,
        string $message,
        private readonly ?int $status = null,
        private readonly ?int $retryAfter = null,
        private readonly ?array $detail = null,
        private readonly ?string $body = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status ?? 0, $previous);
    }

    /** The API's error identifier, e.g. "otp_expired". */
    public function getType(): string
    {
        return $this->type;
    }

    /** HTTP status code, or null when the request never got a response. */
    public function getStatus(): ?int
    {
        return $this->status;
    }

    /** Seconds to wait before retrying, from the Retry-After header. */
    public function getRetryAfter(): ?int
    {
        return $this->retryAfter;
    }

    /**
     * Extra context the endpoint attached. Anti-spam blocks populate this with
     * score, category and reason.
     *
     * @return array<string, mixed>|null
     */
    public function getDetail(): ?array
    {
        return $this->detail;
    }

    /** Raw response body, useful when the payload wasn't the standard envelope. */
    public function getBody(): ?string
    {
        return $this->body;
    }
}
