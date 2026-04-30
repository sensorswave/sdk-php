<?php

declare(strict_types=1);

namespace SensorsWave\Contract;

/**
 * Storage abstraction for the A/B snapshot.
 */
interface ABSpecStoreInterface
{
    public function load(): ?string;

    public function save(string $snapshot): void;
}
