<?php

declare(strict_types=1);

namespace SensorsWave\Contract;

/**
 * Persistence interface for sticky bucketing results.
 */
interface StickyHandlerInterface
{
    /**
     * Load a sticky bucketing result.
     */
    public function getStickyResult(string $key): ?string;

    /**
     * Store a sticky bucketing result.
     */
    public function setStickyResult(string $key, string $result): void;
}
