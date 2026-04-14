<?php

declare(strict_types=1);

namespace SensorsWave\Http;

use RuntimeException;

final class HttpClient implements TransportInterface
{
    private readonly int $timeoutMs;
    private readonly int $connectTimeoutMs;

    public function __construct(int $timeoutMs = 30_000, int $connectTimeoutMs = 5_000)
    {
        $this->timeoutMs = $timeoutMs;
        $this->connectTimeoutMs = $connectTimeoutMs;
    }

    public function send(Request $request): Response
    {
        $ch = curl_init($request->url);
        if ($ch === false) {
            throw new RuntimeException('failed to initialize curl');
        }

        $headers = [];
        foreach ($request->headers as $key => $value) {
            $headers[] = $key . ': ' . $value;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $request->method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $request->body,
            CURLOPT_TIMEOUT_MS => $this->timeoutMs,
            CURLOPT_CONNECTTIMEOUT_MS => $this->connectTimeoutMs,
        ]);

        $body = curl_exec($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException($error !== '' ? $error : 'http request failed');
        }

        return new Response($statusCode, $body);
    }
}
