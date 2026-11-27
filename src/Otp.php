<?php

declare(strict_types=1);

namespace Robase;

use Robase\Exception\ConflictException;
use Robase\Exception\RobaseException;
use Robase\Exception\ValidationException;
use Robase\Model\Otp as OtpModel;
use Robase\Model\SendOtpResult;
use Robase\Model\VerifyOtpResult;

/** The /v1/otp endpoints. Access via `$client->otp`. */
final class Otp
{
    public function __construct(private readonly Client $client)
    {
    }

    /**
     * Generates a code and delivers it by SMS.
     *
     * The credit charge is applied before this returns; delivery itself is
     * asynchronous, so a pending status is the normal success case.
     *
     * @param string                    $phoneNumber Destination in E.164 format, e.g. "+2348012345678".
     * @param int|null                  $codeLength  Digits in the code, 4–8. Server default is 6.
     * @param int|null                  $ttlSeconds  Validity window, up to 3600. Server default is 600.
     * @param array<string, mixed>|null $metadata    Arbitrary data stored with the OTP.
     * @param array<string, mixed>      $options     Per-call overrides; see {@see Client::request()}.
     * @param string|null               $code        Optional 4 to 8 digit code. Null lets Robase generate one.
     *
     * @throws RobaseException
     */
    public function send(
        string $phoneNumber,
        ?int $codeLength = null,
        ?int $ttlSeconds = null,
        ?array $metadata = null,
        array $options = [],
        ?string $code = null,
    ): SendOtpResult {
        if ($phoneNumber === '') {
            throw new ValidationException('validation_error', 'phone_number is required');
        }

        // Unset optionals are omitted rather than sent as zero — the server's
        // defaults only apply when the field is absent.
        $body = ['phone_number' => $phoneNumber];
        if ($codeLength !== null) {
            $body['code_length'] = $codeLength;
        }
        if ($ttlSeconds !== null) {
            $body['ttl_seconds'] = $ttlSeconds;
        }
        if ($metadata !== null) {
            $body['metadata'] = $metadata;
        }
        if ($code !== null) {
            $body['code'] = $code;
        }

        return SendOtpResult::fromArray($this->client->request('POST', '/v1/otp/send', $body, $options));
    }

    /**
     * Verifies a code against a previously sent OTP, using a constant-time
     * comparison server-side.
     *
     * A wrong-but-still-usable code returns normally with `valid` false — it
     * does not throw. Terminal states throw a {@see ConflictException} whose
     * type is otp_expired, otp_already_verified, or max_attempts_exceeded.
     *
     * @param array<string, mixed> $options
     *
     * @throws RobaseException
     */
    public function verify(string $otpId, string $code, array $options = []): VerifyOtpResult
    {
        if ($otpId === '') {
            throw new ValidationException('validation_error', 'otp_id is required');
        }
        if ($code === '') {
            throw new ValidationException('validation_error', 'code is required');
        }

        $body = ['otp_id' => $otpId, 'code' => $code];

        return VerifyOtpResult::fromArray($this->client->request('POST', '/v1/otp/verify', $body, $options));
    }

    /**
     * Retrieves the current state of an OTP.
     *
     * @param array<string, mixed> $options
     *
     * @throws RobaseException
     */
    public function get(string $id, array $options = []): OtpModel
    {
        if ($id === '') {
            throw new ValidationException('validation_error', 'id is required');
        }

        $path = '/v1/otp/' . rawurlencode($id);

        return OtpModel::fromArray($this->client->request('GET', $path, null, $options));
    }
}
