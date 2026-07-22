<?php

declare(strict_types=1);

namespace Robase\Tests\Support;

use Robase\Exception\ConnectionException;
use Robase\Http\HttpResponse;
use Robase\Http\Transport;

/**
 * A scripted {@see Transport} for tests: hand it one response per expected call
 * and it records what the client sent. The last response repeats if the client
 * makes more calls than were scripted.
 */
final class StubTransport implements Transport
{
    /** @var list<array{method: string, url: string, headers: array<string, string>, body: ?string}> */
    public array $requests = [];

    /** @var list<HttpResponse|ConnectionException> */
    private array $queue;

    private int $call = 0;

    /** @param list<HttpResponse|ConnectionException> $queue */
    public function __construct(array $queue)
    {
        $this->queue = $queue;
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     */
    public static function json(array $body, int $status = 200, array $headers = []): HttpResponse
    {
        return new HttpResponse($status, json_encode($body, JSON_THROW_ON_ERROR), $headers);
    }

    public function send(
        string $method,
        string $url,
        array $headers,
        ?string $body,
        float $timeout,
    ): HttpResponse {
        $this->requests[] = [
            'method' => $method,
            'url' => $url,
            'headers' => array_change_key_case($headers),
            'body' => $body,
        ];

        $next = $this->queue[min($this->call, count($this->queue) - 1)];
        $this->call++;

        if ($next instanceof ConnectionException) {
            throw $next;
        }

        return $next;
    }

    /** @return array<string, mixed> The decoded body of the nth request. */
    public function decodedBody(int $index = 0): array
    {
        $body = $this->requests[$index]['body'] ?? null;
        if (!is_string($body)) {
            return [];
        }

        /** @var array<string, mixed> */
        return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    }

    public function header(string $name, int $index = 0): ?string
    {
        return $this->requests[$index]['headers'][strtolower($name)] ?? null;
    }

    public function count(): int
    {
        return count($this->requests);
    }
}
