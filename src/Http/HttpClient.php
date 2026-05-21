<?php

declare(strict_types=1);

namespace SensorsWave\Http;

use RuntimeException;
use SensorsWave\Support\Endpoint;

final class HttpClient implements ConcurrentTransportInterface
{
    private const MULTI_SELECT_TIMEOUT_SECONDS = 1.0;
    private const MULTI_SELECT_FAILURE_SLEEP_MICROS = 1_000;
    private const MULTI_MAX_ITERATIONS = 100_000;

    private readonly int $timeoutMs;
    private readonly int $connectTimeoutMs;
    private readonly int $maxIdleHandles;
    /** @var list<array{origin: string, handle: \CurlHandle}> */
    private array $idleHandles = [];

    public function __construct(int $timeoutMs = 30_000, int $connectTimeoutMs = 5_000, int $maxIdleHandles = 1)
    {
        $this->timeoutMs = max(1, $timeoutMs);
        $this->connectTimeoutMs = max(1, $connectTimeoutMs);
        $this->maxIdleHandles = max(1, $maxIdleHandles);
    }

    public function send(Request $request): Response
    {
        [$origin, $ch] = $this->acquireHandle($request->url);
        $this->configureHandle($ch, $request);
        try {
            $body = curl_exec($ch);
            $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $error = curl_error($ch);
            if ($body === false) {
                throw new RuntimeException($error !== '' ? $error : 'http request failed');
            }

            return new Response($statusCode, $body);
        } finally {
            $this->releaseHandle($origin, $ch);
        }
    }

    public function sendConcurrent(array $requests, int $concurrency): array
    {
        $results = [];
        foreach (array_chunk($requests, max(1, $concurrency)) as $chunk) {
            array_push($results, ...$this->sendConcurrentChunk($chunk));
        }

        return $results;
    }

    public function __destruct()
    {
        foreach ($this->idleHandles as $entry) {
            curl_close($entry['handle']);
        }
    }

    private function configureHandle(\CurlHandle $ch, Request $request): void
    {
        $headers = [];
        foreach ($request->headers as $key => $value) {
            $headers[] = $key . ': ' . $value;
        }

        curl_reset($ch);
        curl_setopt_array($ch, [
            CURLOPT_URL => $request->url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $request->method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $request->body,
            CURLOPT_TIMEOUT_MS => $this->timeoutMs,
            CURLOPT_CONNECTTIMEOUT_MS => $this->connectTimeoutMs,
        ]);
    }

    /**
     * @param list<Request> $requests
     *
     * @return list<Response|\Throwable>
     */
    private function sendConcurrentChunk(array $requests): array
    {
        $multiHandle = curl_multi_init();
        /** @var array<int, array{origin: string, handle: \CurlHandle}> $active */
        $active = [];

        try {
            foreach ($requests as $index => $request) {
                [$origin, $handle] = $this->acquireHandle($request->url);
                $this->configureHandle($handle, $request);
                curl_multi_add_handle($multiHandle, $handle);
                $active[$index] = ['origin' => $origin, 'handle' => $handle];
            }

            $multiError = $this->executeMultiHandle($multiHandle);

            $results = [];
            foreach ($active as $index => $entry) {
                if ($multiError !== null) {
                    $results[$index] = new RuntimeException($multiError->getMessage(), 0, $multiError);
                    continue;
                }

                $handle = $entry['handle'];
                $body = curl_multi_getcontent($handle);
                $statusCode = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
                $errorCode = curl_errno($handle);
                $error = curl_error($handle);
                if ($errorCode !== CURLE_OK) {
                    $results[$index] = new RuntimeException($error !== '' ? $error : curl_strerror($errorCode));
                    continue;
                }
                if (!is_string($body)) {
                    $results[$index] = new RuntimeException('http response body unavailable');
                    continue;
                }

                $results[$index] = new Response($statusCode, $body);
            }

            ksort($results);
            return array_values($results);
        } finally {
            foreach ($active as $entry) {
                curl_multi_remove_handle($multiHandle, $entry['handle']);
                $this->releaseHandle($entry['origin'], $entry['handle']);
            }
            curl_multi_close($multiHandle);
        }
    }

    private function executeMultiHandle(\CurlMultiHandle $multiHandle): ?RuntimeException
    {
        $running = 0;
        for ($iteration = 0; $iteration < self::MULTI_MAX_ITERATIONS; $iteration++) {
            $status = curl_multi_exec($multiHandle, $running);
            if ($status === CURLM_CALL_MULTI_PERFORM) {
                continue;
            }
            if ($status !== CURLM_OK) {
                return new RuntimeException(curl_multi_strerror($status));
            }
            if ($running <= 0) {
                return null;
            }

            $selectResult = curl_multi_select($multiHandle, self::MULTI_SELECT_TIMEOUT_SECONDS);
            if ($selectResult === -1) {
                usleep(self::MULTI_SELECT_FAILURE_SLEEP_MICROS);
            }
        }

        return new RuntimeException('curl multi execution exceeded iteration guard');
    }

    /**
     * @return array{string, \CurlHandle}
     */
    private function acquireHandle(string $url): array
    {
        $origin = Endpoint::originKey($url);
        for ($index = count($this->idleHandles) - 1; $index >= 0; $index--) {
            if ($this->idleHandles[$index]['origin'] !== $origin) {
                continue;
            }
            $entry = $this->idleHandles[$index];
            array_splice($this->idleHandles, $index, 1);
            return [$origin, $entry['handle']];
        }

        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException('failed to initialize curl');
        }

        return [$origin, $handle];
    }

    private function releaseHandle(string $origin, \CurlHandle $handle): void
    {
        while (count($this->idleHandles) >= $this->maxIdleHandles) {
            $entry = array_shift($this->idleHandles);
            curl_close($entry['handle']);
        }

        $this->idleHandles[] = ['origin' => $origin, 'handle' => $handle];
    }
}
