<?php

declare(strict_types=1);

namespace Robase\Exception;

/** A webhook signature did not authenticate the payload. */
final class SignatureVerificationException extends RobaseException
{
    public function __construct(string $message = 'Webhook signature verification failed')
    {
        parent::__construct('invalid_signature', $message);
    }
}
