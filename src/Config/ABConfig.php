<?php

declare(strict_types=1);

namespace SensorsWave\Config;

use SensorsWave\Contract\StickyHandlerInterface;

/**
 * A/B 能力配置。
 */
final class ABConfig
{
    public function __construct(
        public readonly string $projectSecret = '',
        public readonly string $metaEndpoint = '',
        public readonly string $metaUriPath = '/ab/all4eval',
        public readonly int $metaLoadIntervalMs = 60_000,
        public readonly ?StickyHandlerInterface $stickyHandler = null,
        public readonly string $loadABSpecs = '',
    ) {
    }
}
