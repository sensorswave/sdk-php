<?php

declare(strict_types=1);

namespace SensorsWave\Storage;

/**
 * A/B snapshot 存储元数据。
 */
final class ABSpecStoreMetadata
{
    public function __construct(
        public readonly ?int $updatedAtMs,
    ) {
    }
}
