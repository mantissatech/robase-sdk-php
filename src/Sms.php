<?php

declare(strict_types=1);

namespace Robase;

use Robase\Exception\RobaseException;
use Robase\Exception\SpamDetectedException;
use Robase\Exception\ValidationException;
use Robase\Model\Message;
use Robase\Model\SendSmsResult;

/** The /v1/sms endpoints. Access via `$client->sms`. */
final class Sms
{
    /** The longest message body the API accepts. */
    public const MAX_MESSAGE_LENGTH = 918;

    public function __construct(private readonly Client $client)
    {
    }

    /**
     * Queues a transactional SMS. Routing, billing and provider failover are the
     * same as for OTPs.
     *
     * Returns as soon as the message is queued and charged — the status is
     * always pending. Subscribe to the sms.delivered / sms.failed webhooks, or
     * poll {@see get()}, to learn the outcome.
     *
     * @param array<string, mixed>|null $metadata Arbitrary data stored with the message.
     * @param array<string, mixed>      $options  Per-call overrides; see {@see Client::request()}.
     *
     * @throws SpamDetectedException When anti-spam blocks the message; its detail
     *                               holds the score, category and reason.
     * @throws RobaseException
     */
    public function send(
        string $phoneNumber,
        string $message,
        ?array $metadata = null,
        array $options = [],
    ): SendSmsResult {
        if ($phoneNumber === '') {
            throw new ValidationException('validation_error', 'phone_number is required');
        }
        if ($message === '') {
            throw new ValidationException('validation_error', 'message is required');
        }
        if (strlen($message) > self::MAX_MESSAGE_LENGTH) {
            throw new ValidationException(
                'validation_error',
                sprintf('message must be at most %d characters', self::MAX_MESSAGE_LENGTH),
            );
        }

        $body = ['phone_number' => $phoneNumber, 'message' => $message];
        if ($metadata !== null) {
            $body['metadata'] = $metadata;
        }

        return SendSmsResult::fromArray($this->client->request('POST', '/v1/sms/send', $body, $options));
    }

    /**
     * Retrieves the current state of a transactional SMS.
     *
     * @param array<string, mixed> $options
     *
     * @throws RobaseException
     */
    public function get(string $id, array $options = []): Message
    {
        if ($id === '') {
            throw new ValidationException('validation_error', 'id is required');
        }

        $path = '/v1/sms/' . rawurlencode($id);

        return Message::fromArray($this->client->request('GET', $path, null, $options));
    }
}
