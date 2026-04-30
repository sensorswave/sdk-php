<?php

declare(strict_types=1);

namespace SensorsWave\ABTesting;

use SensorsWave\ABTesting\Model\ABEnv;
use SensorsWave\ABTesting\Model\ABSpec;

/**
 * A/B metadata snapshot.
 */
final class Storage
{
    /**
     * @param array<string, ABSpec> $specs
     */
    public function __construct(
        public readonly int $updateTime,
        public readonly ABEnv $abEnv,
        private array $specs,
    ) {
    }

    /**
     * Return whether the given spec exists.
     */
    public function hasSpec(string $key): bool
    {
        return isset($this->specs[$key]);
    }

    /**
     * Return the spec for the given key, or null.
     */
    public function getSpec(string $key): ?ABSpec
    {
        return $this->specs[$key] ?? null;
    }

    /**
     * Return every spec keyed by spec key.
     *
     * @return array<string, ABSpec>
     */
    public function allSpecs(): array
    {
        return $this->specs;
    }

    /**
     * Export as a JSON-encodable structure that can be reloaded.
     *
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'code' => 0,
            'data' => [
                'update' => true,
                'update_time' => $this->updateTime,
                'ab_env' => $this->abEnv->toArray(),
                'ab_specs' => array_map(
                    static fn (ABSpec $spec): array => $spec->toArray(),
                    array_values($this->specs)
                ),
            ],
        ];
    }
}
