<?php

declare(strict_types=1);

namespace SensorsWave\Tests\Support;

use SensorsWave\Contract\ABSpecStoreInterface;
use SensorsWave\Storage\ABSpecStoreMetadata;

final class MemoryABSpecStore implements ABSpecStoreInterface
{
    public function __construct(
        private ?string $snapshot = null,
        private ?int $updatedAtMs = null,
    ) {
    }

    public function load(): ?string
    {
        return $this->snapshot;
    }

    public function save(string $snapshot): void
    {
        $this->snapshot = $snapshot;
        $this->updatedAtMs = (int) floor(microtime(true) * 1000);
    }

    public function metadata(): ABSpecStoreMetadata
    {
        return new ABSpecStoreMetadata($this->updatedAtMs);
    }

    public function setUpdatedAtMs(?int $updatedAtMs): void
    {
        $this->updatedAtMs = $updatedAtMs;
    }
}
