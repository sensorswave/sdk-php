<?php

declare(strict_types=1);

namespace SensorsWave\Tests\Support;

use SensorsWave\Contract\ABSpecStoreInterface;

final class MemoryABSpecStore implements ABSpecStoreInterface
{
    public function __construct(
        private ?string $snapshot = null,
    ) {
    }

    public function load(): ?string
    {
        return $this->snapshot;
    }

    public function save(string $snapshot): void
    {
        $this->snapshot = $snapshot;
    }
}
