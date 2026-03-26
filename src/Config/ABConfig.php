<?php

declare(strict_types=1);

namespace SensorsWave\Config;

use SensorsWave\Contract\ABSpecStoreInterface;
use SensorsWave\Contract\StickyHandlerInterface;
use SensorsWave\Storage\LocalFileABSpecStore;

/**
 * A/B 能力配置。
 */
final class ABConfig
{
    public readonly ABSpecStoreInterface $abSpecStore;

    public function __construct(
        public readonly string $projectSecret = '',
        public readonly string $metaEndpoint = '',
        public readonly string $metaUriPath = '/ab/all4eval',
        public readonly int $metaLoadIntervalMs = 60_000,
        public readonly ?StickyHandlerInterface $stickyHandler = null,
        public readonly string $loadABSpecs = '',
        ?ABSpecStoreInterface $abSpecStore = null,
    ) {
        $this->abSpecStore = $abSpecStore ?? new LocalFileABSpecStore();
    }
}
