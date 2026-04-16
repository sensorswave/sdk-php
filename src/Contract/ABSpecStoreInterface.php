<?php

declare(strict_types=1);

namespace SensorsWave\Contract;

/**
 * A/B snapshot 存储抽象。
 */
interface ABSpecStoreInterface
{
    public function load(): ?string;

    public function save(string $snapshot): void;
}
