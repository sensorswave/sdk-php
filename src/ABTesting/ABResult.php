<?php

declare(strict_types=1);

namespace SensorsWave\ABTesting;

/**
 * A/B 求值结果。
 */
final class ABResult
{
    /**
     * @param array<string, mixed> $variantParamValue
     */
    public function __construct(
        public int $id = 0,
        public string $key = '',
        public int $type = 0,
        public ?string $variantId = null,
        public array $variantParamValue = [],
        public bool $disableImpress = false,
    ) {
    }

    /**
     * 判断 gate 是否命中。
     */
    public function checkFeatureGate(): bool
    {
        return $this->variantId === 'pass';
    }
}
