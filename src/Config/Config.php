<?php

declare(strict_types=1);

namespace SensorsWave\Config;

use SensorsWave\Contract\LoggerInterface;
use SensorsWave\Support\DefaultLogger;

/**
 * SDK 运行配置。
 */
final class Config
{
    public readonly LoggerInterface $logger;

    public function __construct(
        ?LoggerInterface $logger = null,
        public readonly string $trackUriPath = '/sa?project=%s',
        public readonly int $flushIntervalMs = 10_000,
        public readonly int $httpConcurrency = 10,
        public readonly int $httpTimeoutMs = 3_000,
        public readonly int $httpRetry = 2,
        public readonly ?ABConfig $ab = null,
    ) {
        $this->logger = $logger ?? new DefaultLogger();
    }
}
