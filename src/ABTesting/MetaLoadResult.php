<?php

declare(strict_types=1);

namespace SensorsWave\ABTesting;

/**
 * Result of a remote metadata load.
 */
final class MetaLoadResult
{
    public function __construct(
        public readonly bool $update,
        public readonly Storage $storage,
    ) {
    }
}
