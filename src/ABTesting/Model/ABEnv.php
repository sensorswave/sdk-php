<?php

declare(strict_types=1);

namespace SensorsWave\ABTesting\Model;

/**
 * A/B 环境配置。
 */
final class ABEnv
{
    public function __construct(
        public readonly bool $alwaysTrack = false,
    ) {
    }

    /**
     * 从数组创建环境对象。
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self((bool) ($data['always_track'] ?? false));
    }
}
