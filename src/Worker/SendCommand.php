<?php

declare(strict_types=1);

namespace SensorsWave\Worker;

use SensorsWave\Config\Config;
use SensorsWave\Http\ConcurrentTransportInterface;
use SensorsWave\Http\HttpClient;
use SensorsWave\Http\Request;
use SensorsWave\Http\Response;
use SensorsWave\Http\TransportInterface;
use SensorsWave\Storage\QueueMessage;
use SensorsWave\Support\Endpoint;

/**
 * Worker that drains the local event queue and posts batches to the server.
 */
final class SendCommand
{
    private readonly TransportInterface $transport;

    public function __construct(
        private readonly string $endpoint,
        private readonly string $sourceToken,
        private readonly Config $config,
        ?TransportInterface $transport = null,
    ) {
        $this->transport = $transport ?? $config->transport ?? new HttpClient(
            $config->httpTimeoutMs,
            $config->httpConnectTimeoutMs,
            $config->httpConcurrency
        );
    }

    public function run(int $limit = 50): int
    {
        $concurrency = max(1, $this->config->httpConcurrency);
        while (true) {
            $jobs = $this->dequeueJobs($limit, $concurrency);
            if ($jobs === []) {
                return 0;
            }

            $results = $this->deliverJobs($jobs);
            $failed = false;
            foreach ($jobs as $index => $job) {
                if ($results[$index] ?? false) {
                    $this->config->eventQueue->ack($job['messages']);
                } else {
                    $this->config->eventQueue->nack($job['messages']);
                    $failed = true;
                }
            }

            if ($failed) {
                return 1;
            }
        }
    }

    /**
     * @return list<array{messages: list<QueueMessage>, request: Request}>
     */
    private function dequeueJobs(int $limit, int $concurrency): array
    {
        $jobs = [];
        for ($index = 0; $index < $concurrency; $index++) {
            $messages = $this->config->eventQueue->dequeue($limit);
            if ($messages === []) {
                break;
            }
            $jobs[] = [
                'messages' => $messages,
                'request' => $this->buildRequest($messages),
            ];
        }

        return $jobs;
    }

    /**
     * @param list<QueueMessage> $messages
     */
    private function buildRequest(array $messages): Request
    {
        $body = '[' . implode(',', array_map(
            fn(QueueMessage $m) => $m->payload,
            $messages
        )) . ']';

        $headers = [
            'Content-Type' => 'application/json',
            'SourceToken' => $this->sourceToken,
        ];
        $requestBody = $body;
        if ($this->config->gzipThresholdBytes > 0 && strlen($body) > $this->config->gzipThresholdBytes) {
            $compressed = gzencode($body);
            if ($compressed !== false) {
                $requestBody = $compressed;
                $headers['Content-Encoding'] = 'gzip';
            }
        }

        return new Request(
            'POST',
            Endpoint::normalizeEndpoint($this->endpoint) . Endpoint::normalizeUriPath($this->config->trackUriPath, '/in/track'),
            $headers,
            $requestBody
        );
    }

    /**
     * @param list<array{messages: list<QueueMessage>, request: Request}> $jobs
     *
     * @return list<bool>
     */
    private function deliverJobs(array $jobs): array
    {
        if ($this->transport instanceof ConcurrentTransportInterface) {
            return $this->deliverConcurrent($this->transport, $jobs);
        }

        return array_map(
            fn(array $job): bool => $this->deliver($job['request']),
            $jobs
        );
    }

    /**
     * @param list<array{messages: list<QueueMessage>, request: Request}> $jobs
     *
     * @return list<bool>
     */
    private function deliverConcurrent(ConcurrentTransportInterface $transport, array $jobs): array
    {
        $attempts = max(0, $this->config->httpRetry) + 1;
        $results = array_fill(0, count($jobs), false);
        $pending = array_keys($jobs);
        $concurrency = max(1, $this->config->httpConcurrency);

        for ($attempt = 0; $attempt < $attempts && $pending !== []; $attempt++) {
            $responses = $transport->sendConcurrent(
                array_map(fn(int $index): Request => $jobs[$index]['request'], $pending),
                $concurrency
            );
            $retry = [];
            foreach ($responses as $offset => $result) {
                $jobIndex = $pending[$offset];
                if ($result instanceof Response) {
                    if ($this->isSuccessfulTrackResponse($result)) {
                        $results[$jobIndex] = true;
                        continue;
                    }
                    if ($this->isRetryableTrackResponse($result) && $attempt < $attempts - 1) {
                        $retry[] = $jobIndex;
                    }
                    continue;
                }

                $this->config->logger->warn(
                    'concurrent delivery transport error',
                    ['error' => $result->getMessage(), 'attempt' => $attempt + 1]
                );

                if ($attempt < $attempts - 1) {
                    $retry[] = $jobIndex;
                }
            }
            $pending = $retry;
        }

        return $results;
    }

    private function deliver(Request $request): bool
    {
        $attempts = max(0, $this->config->httpRetry) + 1;
        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            try {
                $response = $this->transport->send($request);
                if ($this->isSuccessfulTrackResponse($response)) {
                    return true;
                }

                if (!$this->isRetryableTrackResponse($response)) {
                    return false;
                }
            } catch (\Throwable $e) {
                $this->config->logger->warn(
                    'delivery transport error',
                    ['error' => $e->getMessage(), 'attempt' => $attempt + 1]
                );

                if ($attempt === $attempts - 1) {
                    return false;
                }
            }
        }

        return false;
    }

    private function isSuccessfulTrackResponse(Response $response): bool
    {
        return $response->statusCode === 200;
    }

    private function isRetryableTrackResponse(Response $response): bool
    {
        return $response->statusCode >= 500;
    }

}
