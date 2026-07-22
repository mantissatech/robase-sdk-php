<?php

declare(strict_types=1);

namespace Robase\Exception;

/**
 * The request failed validation (HTTP 400). Also thrown locally, before any
 * request, for obviously bad arguments.
 */
final class ValidationException extends RobaseException
{
}
