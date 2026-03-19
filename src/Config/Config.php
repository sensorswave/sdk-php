<?php

declare(strict_types=1);

namespace SensorsWave\Config;

use Closure;
use SensorsWave\Contract\LoggerInterface;
use SensorsWave\Http\TransportInterface;
use SensorsWave\Support\DefaultLogger;

/**
 * SDK 运行配置。
 */
final class Config
{
    public readonly LoggerInterface $logger;
    public readonly ?Closure $onTrackFailHandler;

    public function __construct(
        ?LoggerInterface $logger = null,
        public readonly string $trackUriPath = '/in/track',
        public readonly int $flushIntervalMs = 10_000,
        public readonly int $httpConcurrency = 10,
        public readonly int $httpTimeoutMs = 3_000,
        public readonly int $httpRetry = 2,
        ?callable $onTrackFailHandler = null,
        public readonly ?ABConfig $ab = null,
        public readonly ?TransportInterface $transport = null,
    ) {
        $this->logger = $logger ?? new DefaultLogger();
        $this->onTrackFailHandler = $onTrackFailHandler !== null
            ? Closure::fromCallable($onTrackFailHandler)
            : null;
    }
}
