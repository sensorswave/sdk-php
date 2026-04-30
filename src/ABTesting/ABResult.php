<?php

declare(strict_types=1);

namespace SensorsWave\ABTesting;

use JsonException;

/**
 * A/B evaluation result.
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
        public ?string $decisionRuleId = null,
    ) {
    }

    /**
     * Return whether the feature gate passes.
     */
    public function checkFeatureGate(): bool
    {
        return $this->variantId === 'pass';
    }

    /**
     * Read a string parameter from the variant payload.
     */
    public function getString(string $key, string $fallback): string
    {
        $value = $this->variantParamValue[$key] ?? null;
        return is_string($value) ? $value : $fallback;
    }

    /**
     * Read a numeric parameter from the variant payload.
     */
    public function getNumber(string $key, float $fallback): float
    {
        $value = $this->variantParamValue[$key] ?? null;
        return is_int($value) || is_float($value) ? (float) $value : $fallback;
    }

    /**
     * Read a boolean parameter from the variant payload.
     */
    public function getBool(string $key, bool $fallback): bool
    {
        $value = $this->variantParamValue[$key] ?? null;
        return is_bool($value) ? $value : $fallback;
    }

    /**
     * Read a list parameter from the variant payload.
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
     * Read a map parameter from the variant payload.
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

    /**
     * Export the variant payload as a JSON string.
     *
     * @throws JsonException
     */
    public function jsonPayload(): string
    {
        return json_encode($this->variantParamValue, JSON_THROW_ON_ERROR);
    }
}
