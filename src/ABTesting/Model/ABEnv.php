<?php

declare(strict_types=1);

namespace SensorsWave\ABTesting\Model;

/**
 * A/B environment configuration.
 */
final class ABEnv
{
    public function __construct(
        public readonly bool $alwaysTrack = false,
    ) {
    }

    /**
     * Build an env from a decoded payload array.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self((bool) ($data['always_track'] ?? false));
    }

    /**
     * Export as an associative array.
     *
     * @return array<string, bool>
     */
    public function toArray(): array
    {
        return [
            'always_track' => $this->alwaysTrack,
        ];
    }
}
