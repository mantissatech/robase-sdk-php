<?php

declare(strict_types=1);

namespace Robase\Exception;

/**
 * Anti-spam blocked the message (HTTP 403).
 *
 * {@see RobaseException::getDetail()} holds the score, category and reason.
 */
final class SpamDetectedException extends RobaseException
{
}
