# Robase PHP SDK

Official PHP client for the [Robase](https://robase.dev) SMS OTP and
transactional SMS API.

No framework dependencies — just `ext-curl` and `ext-json`. PHP 8.1+.

## Install

```bash
composer require robase/sdk
```

## Quick start

```php
<?php

require 'vendor/autoload.php';

$robase = new Robase\Client(getenv('ROBASE_API_KEY'));

$sent = $robase->otp->send('+2348012345678');
echo $sent->id, ' expires ', $sent->expiresAt?->format('c'), PHP_EOL;

// ...after the user types in the code they received:
$result = $robase->otp->verify($sent->id, '123456');
if ($result->valid) {
    echo "verified\n";
}
```

## Client options

```php
$robase = new Robase\Client(
    apiKey: 'robe_...',
    baseUrl: 'http://localhost:8080',   // default: https://api.robase.dev
    timeout: 10.0,                      // seconds, default: 30.0
    maxRetries: 3,                      // default: 2
    transport: $myTransport,            // default: CurlTransport
    extraHeaders: ['X-App' => 'checkout'],
);
```

Every method takes a final `$options` array for per-call overrides:

```php
$robase->sms->send('+2348012345678', 'Shipped!', options: [
    'idempotency_key' => "order-{$orderId}",
    'max_retries' => 0,
    'headers' => ['X-Trace-Id' => $traceId],
]);
```

To route requests through Guzzle or a PSR-18 client, implement
`Robase\Http\Transport` and pass it in — that's also how the test suite stubs
the network.

## API

### OTP

| Method | Endpoint |
|---|---|
| `$robase->otp->send($phoneNumber, $codeLength, $ttlSeconds, $metadata, $options)` | `POST /v1/otp/send` |
| `$robase->otp->verify($otpId, $code, $options)` | `POST /v1/otp/verify` |
| `$robase->otp->get($id, $options)` | `GET /v1/otp/{id}` |

```php
$sent = $robase->otp->send(
    phoneNumber: '+2348012345678',  // required, E.164
    codeLength: 6,                  // optional, 4–8, defaults to 6
    ttlSeconds: 600,                // optional, ≤3600, defaults to 600
    metadata: ['user_id' => 'u_123'],
);
```

A wrong-but-not-yet-exhausted code is **not** an exception — `verify` returns
with `valid` false and `attemptsRemaining` set:

```php
use Robase\Exception\ConflictException;

try {
    $result = $robase->otp->verify($otpId, $code);
    if ($result->valid) {
        // success
    } else {
        printf("wrong code, %d attempts left\n", $result->attemptsRemaining);
    }
} catch (ConflictException $e) {
    // $e->getType() is otp_expired | otp_already_verified | max_attempts_exceeded
}
```

### SMS

| Method | Endpoint |
|---|---|
| `$robase->sms->send($phoneNumber, $message, $metadata, $options)` | `POST /v1/sms/send` |
| `$robase->sms->get($id, $options)` | `GET /v1/sms/{id}` |

```php
$msg = $robase->sms->send(
    phoneNumber: '+2348012345678',
    message: 'Your order #12345 has shipped.',  // ≤918 characters
    metadata: ['order_id' => '12345'],
);
```

`send` returns as soon as the message is queued and charged — the status is
always `pending`. Subscribe to the `sms.delivered` / `sms.failed` webhooks, or
poll `sms->get()`, to learn the outcome.

## Errors

Every non-2xx response throws a subclass of `Robase\Exception\RobaseException`.
Catch the subclass, or inspect `getType()` when several types share a status:

```php
use Robase\Exception\InsufficientCreditsException;
use Robase\Exception\RobaseException;

try {
    $robase->otp->send($phoneNumber);
} catch (InsufficientCreditsException $e) {
    // top up and retry
} catch (RobaseException $e) {
    error_log("{$e->getType()}: {$e->getMessage()} (HTTP {$e->getStatus()})");
}
```

| Exception | HTTP | `getType()` |
|---|---|---|
| `ValidationException` | 400 | `validation_error`, `invalid_phone`, `country_not_supported` |
| `AuthenticationException` | 401 | `unauthorized` |
| `InsufficientCreditsException` | 402 | `insufficient_credits` |
| `SpamDetectedException` | 403 | `spam_detected` |
| `NotFoundException` | 404 | `otp_not_found`, `sms_not_found` |
| `ConflictException` | 409 | `otp_expired`, `otp_already_verified`, `max_attempts_exceeded` |
| `RateLimitException` | 429 | `rate_limited` — see `getRetryAfter()` |
| `ServerException` | 5xx | `internal_error` |
| `ConnectionException` | — | network failure or timeout |

`ValidationException` is also thrown locally, before any request, for obviously
bad arguments — an empty `$phoneNumber`, an over-long `$message`.

When anti-spam blocks a message, `getDetail()` carries `score`, `category` and
`reason`.

## Retries and idempotency

Network errors, `429`s and `5xx`s are retried with exponential backoff and full
jitter, honouring `Retry-After` when the server sends it. Every `POST` carries an
auto-generated `Idempotency-Key`, and a retry reuses the same key — so a retried
send replays the original response instead of charging you twice.

Pass `idempotency_key` in `$options` to reuse a key across processes. Keys are
honoured for 24 hours.

## Webhooks

Robase signs every delivery with `X-Robase-Signature` — a hex HMAC-SHA256 of the
raw body under your webhook secret.

```php
use Robase\Exception\SignatureVerificationException;
use Robase\Webhook;
use Robase\WebhookEvent;

// The raw bytes matter — a re-encoded body won't match the signature.
$payload = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_ROBASE_SIGNATURE'] ?? null;

try {
    $event = Webhook::parse($payload, $signature, $secret);
} catch (SignatureVerificationException) {
    http_response_code(401);
    exit;
}

match ($event->event) {
    WebhookEvent::OtpVerified   => handleVerified($event->string('id')),
    WebhookEvent::SmsDelivered  => handleDelivered($event->string('id')),
    WebhookEvent::CreditLow     => alertLowBalance($event->int('balance')),
    default                     => null,
};

http_response_code(200);
```

Events: `otp.sent`, `otp.delivered`, `otp.verified`, `otp.expired`, `otp.failed`,
`sms.sent`, `sms.delivered`, `sms.failed`, `credit_balance.low`,
`credit_balance.exhausted`, `credit_balance.topped_up`.

## Development

```bash
composer install
composer test
```

## License

MIT
