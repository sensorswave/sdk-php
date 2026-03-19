<?php

declare(strict_types=1);

namespace SensorsWave\ABTesting;

/**
 * 远程元数据加载结果。
 */
final class MetaLoadResult
{
    public function __construct(
        public readonly bool $update,
        public readonly Storage $storage,
    ) {
    }
}
