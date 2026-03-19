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

    /**
     * 读取字符串参数。
     */
    public function getString(string $key, string $fallback): string
    {
        $value = $this->variantParamValue[$key] ?? null;
        return is_string($value) ? $value : $fallback;
    }

    /**
     * 读取数值参数。
     */
    public function getNumber(string $key, float $fallback): float
    {
        $value = $this->variantParamValue[$key] ?? null;
        return is_int($value) || is_float($value) ? (float) $value : $fallback;
    }

    /**
     * 读取布尔参数。
     */
    public function getBool(string $key, bool $fallback): bool
    {
        $value = $this->variantParamValue[$key] ?? null;
        return is_bool($value) ? $value : $fallback;
    }

    /**
     * 读取列表参数。
     *
     * @param list<mixed> $fallback
     *
     * @return list<mixed>
     */
    public function getSlice(string $key, array $fallback): array
    {
        $value = $this->variantParamValue[$key] ?? null;
        return is_array($value) && array_is_list($value) ? $value : $fallback;
    }

    /**
     * 读取字典参数。
     *
     * @param array<string, mixed> $fallback
     *
     * @return array<string, mixed>
     */
    public function getMap(string $key, array $fallback): array
    {
        $value = $this->variantParamValue[$key] ?? null;
        return is_array($value) && !array_is_list($value) ? $value : $fallback;
    }
}
