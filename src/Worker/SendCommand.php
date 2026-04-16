<?php

declare(strict_types=1);

namespace SensorsWave\Worker;

use SensorsWave\Config\Config;
use SensorsWave\Http\HttpClient;
use SensorsWave\Http\Request;
use SensorsWave\Http\Response;
use SensorsWave\Http\TransportInterface;
use SensorsWave\Storage\QueueMessage;

/**
 * 事件发送任务。
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
        $this->transport = $transport ?? $config->transport ?? new HttpClient($config->httpTimeoutMs);
    }

    public function run(int $limit = 50): int
    {
        $status = 0;
        while (true) {
            $messages = $this->config->eventQueue->dequeue($limit);
            if ($messages === []) {
                return $status;
            }

            $body = '[' . implode(',', array_map(
                fn(QueueMessage $m) => $m->payload,
                $messages
            )) . ']';

            $request = new Request(
                'POST',
                $this->normalizeEndpoint($this->endpoint) . $this->normalizeUriPath($this->config->trackUriPath, '/in/track'),
                [
                    'Content-Type' => 'application/json',
                    'SourceToken' => $this->sourceToken,
                ],
                $body
            );

            if ($this->deliver($request)) {
                $this->config->eventQueue->ack($messages);
                continue;
            }

            $this->config->eventQueue->nack($messages);
            $status = 1;
            return $status;
        }
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
            } catch (\Throwable) {
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

    private function normalizeEndpoint(string $endpoint): string
    {
        $parts = parse_url($endpoint);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new \InvalidArgumentException('endpoint is invalid');
        }

        $scheme = $parts['scheme'];
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new \InvalidArgumentException('scheme must be http or https');
        }

        $normalized = $scheme . '://' . $parts['host'];
        if (isset($parts['port'])) {
            $normalized .= ':' . $parts['port'];
        }

        return $normalized;
    }

    private function normalizeUriPath(string $uriPath, string $defaultPath): string
    {
        if ($uriPath === '') {
            return $defaultPath;
        }

        return str_starts_with($uriPath, '/') ? $uriPath : '/' . $uriPath;
    }
}
