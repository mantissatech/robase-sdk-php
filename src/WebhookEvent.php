<?php

declare(strict_types=1);

namespace Robase;

/** Every event Robase can deliver to your webhook endpoint. */
enum WebhookEvent: string
{
    case OtpSent = 'otp.sent';
    case OtpDelivered = 'otp.delivered';
    case OtpVerified = 'otp.verified';
    case OtpExpired = 'otp.expired';
    case OtpFailed = 'otp.failed';

    case SmsSent = 'sms.sent';
    case SmsDelivered = 'sms.delivered';
    case SmsFailed = 'sms.failed';

    case CreditLow = 'credit_balance.low';
    case CreditExhausted = 'credit_balance.exhausted';
    case CreditToppedUp = 'credit_balance.topped_up';
}
