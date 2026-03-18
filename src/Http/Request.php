<?php

declare(strict_types=1);

namespace SensorsWave\Http;

/**
 * HTTP 请求对象。
 */
final class Request
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly string $method,
        public readonly string $url,
        public readonly array $headers = [],
        public readonly string $body = '',
    ) {
    }
}
