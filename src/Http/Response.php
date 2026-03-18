<?php

declare(strict_types=1);

namespace SensorsWave\Http;

/**
 * HTTP 响应对象。
 */
final class Response
{
    public function __construct(
        public readonly int $statusCode,
        public readonly string $body = '',
    ) {
    }
}
