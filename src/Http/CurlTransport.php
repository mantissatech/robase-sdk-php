<?php

declare(strict_types=1);

namespace Robase\Http;

use Robase\Exception\ConnectionException;

/** The default {@see Transport}, backed by ext-curl. */
final class CurlTransport implements Transport
{
    public function send(
        string $method,
        string $url,
        array $headers,
        ?string $body,
        float $timeout,
    ): HttpResponse {
        $handle = curl_init();
        if ($handle === false) {
            throw new ConnectionException('Failed to initialise cURL');
        }

        $formattedHeaders = [];
        foreach ($headers as $name => $value) {
            $formattedHeaders[] = $name . ': ' . $value;
        }

        $responseHeaders = [];

        curl_setopt_array($handle, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $formattedHeaders,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => (int) round($timeout * 1000),
            CURLOPT_CONNECTTIMEOUT_MS => (int) round(min($timeout, 10.0) * 1000),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADERFUNCTION => static function ($_, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($line);
            },
        ]);

        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        $responseBody = curl_exec($handle);
        $errorNumber = curl_errno($handle);
        $errorMessage = curl_error($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        if ($errorNumber !== 0 || $responseBody === false) {
            throw new ConnectionException(
                sprintf('cURL request to %s failed: %s', $url, $errorMessage ?: 'unknown error'),
            );
        }

        return new HttpResponse($status, (string) $responseBody, $responseHeaders);
    }
}
