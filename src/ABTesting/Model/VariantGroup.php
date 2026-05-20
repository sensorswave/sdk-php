<?php

declare(strict_types=1);

namespace SensorsWave\ABTesting\Model;

/**
 * Config variant allocation bucket with cumulative rollout threshold.
 */
final class VariantGroup
{
    public function __construct(
        public readonly string $variantId,
        public readonly float $rollout,
    ) {
    }

    /**
     * Build a variant group from a decoded payload array.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['variant_id'] ?? ''),
            (float) ($data['rollout'] ?? 0),
        );
    }

    /**
     * Export as an associative array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'variant_id' => $this->variantId,
            'rollout' => $this->rollout,
        ];
    }
}
