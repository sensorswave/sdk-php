<?php

declare(strict_types=1);

namespace SensorsWave\Http;

/**
 * HTTP response payload.
 */
final class Response
{
    public function __construct(
        public readonly int $statusCode,
        public readonly string $body = '',
    ) {
    }
}
