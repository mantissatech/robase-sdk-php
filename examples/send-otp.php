<?php

declare(strict_types=1);

/*
 * Sends an OTP and verifies a code read from stdin.
 *
 *   ROBASE_API_KEY=robe_... php examples/send-otp.php +2348012345678
 */

require __DIR__ . '/../vendor/autoload.php';

use Robase\Client;
use Robase\Exception\ConflictException;
use Robase\Exception\InsufficientCreditsException;
use Robase\Exception\ValidationException;

$phoneNumber = $argv[1] ?? null;
if ($phoneNumber === null) {
    fwrite(STDERR, "usage: php examples/send-otp.php <phone-number-in-e164>\n");
    exit(1);
}

$apiKey = getenv('ROBASE_API_KEY');
if ($apiKey === false || $apiKey === '') {
    fwrite(STDERR, "ROBASE_API_KEY is not set\n");
    exit(1);
}

$robase = new Client($apiKey);

try {
    $sent = $robase->otp->send($phoneNumber, codeLength: 6, ttlSeconds: 300);
} catch (InsufficientCreditsException) {
    fwrite(STDERR, "out of credits — top up at https://robase.dev\n");
    exit(1);
} catch (ValidationException $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}

printf(
    "sent %s to %s (%d credit(s)), expires %s\n",
    $sent->id,
    $sent->phoneNumber,
    $sent->creditCost,
    $sent->expiresAt?->format('c') ?? 'unknown',
);

echo 'enter the code: ';
$code = trim((string) fgets(STDIN));

try {
    $result = $robase->otp->verify($sent->id, $code);
} catch (ConflictException $error) {
    fwrite(STDERR, "cannot verify: {$error->getType()}\n");
    exit(1);
}

if ($result->valid) {
    echo "verified ✓\n";
} else {
    printf("wrong code — %d attempt(s) left\n", $result->attemptsRemaining ?? 0);
}
