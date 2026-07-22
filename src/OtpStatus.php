<?php

declare(strict_types=1);

namespace Robase;

/** Lifecycle state of an OTP. */
enum OtpStatus: string
{
    case Pending = 'pending';
    case Sent = 'sent';
    case Verified = 'verified';
    case Expired = 'expired';
    case Failed = 'failed';
}
