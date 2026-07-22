<?php

declare(strict_types=1);

namespace Robase\Http;

/** A raw HTTP response, as returned by a {@see Transport}. */
final class HttpResponse
{
    /**
     * @param int                   $status  HTTP status code.
     * @param string                $body    Raw response body.
     * @param array<string, string> $headers Response headers, keyed by lowercase name.
     */
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly array $headers = [],
    ) {
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function isSuccessful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }
}
