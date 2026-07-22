<?php

declare(strict_types=1);

namespace Robase\Exception;

/** The workspace balance is below the send cost (HTTP 402). */
final class InsufficientCreditsException extends RobaseException
{
}
