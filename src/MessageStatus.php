<?php

declare(strict_types=1);

namespace Robase;

/** Delivery state of a transactional SMS. */
enum MessageStatus: string
{
    case Pending = 'pending';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Failed = 'failed';
}
