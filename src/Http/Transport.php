<?php

declare(strict_types=1);

namespace Robase\Http;

use Robase\Exception\ConnectionException;

/**
 * Sends HTTP requests on the client's behalf.
 *
 * The default is {@see CurlTransport}. Implement this to route requests through
 * Guzzle, a PSR-18 client, or a stub in tests.
 */
interface Transport
{
    /**
     * @param 'GET'|'POST'          $method
     * @param array<string, string> $headers
     * @param string|null           $body    Request body, or null for GET.
     * @param float                 $timeout Seconds.
     *
     * @throws ConnectionException When no response could be obtained.
     */
    public function send(
        string $method,
        string $url,
        array $headers,
        ?string $body,
        float $timeout,
    ): HttpResponse;
}
