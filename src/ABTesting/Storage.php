<?php

declare(strict_types=1);

namespace SensorsWave\ABTesting;

use SensorsWave\ABTesting\Model\ABEnv;
use SensorsWave\ABTesting\Model\ABSpec;

/**
 * A/B 元数据快照。
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
     * 判断是否存在指定 spec。
     */
    public function hasSpec(string $key): bool
    {
        return isset($this->specs[$key]);
    }

    /**
     * 获取指定 spec。
     */
    public function getSpec(string $key): ?ABSpec
    {
        return $this->specs[$key] ?? null;
    }

    /**
     * 返回全部 spec。
     *
     * @return array<string, ABSpec>
     */
    public function allSpecs(): array
    {
        return $this->specs;
    }

    /**
     * 导出为可回灌的 JSON 结构。
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
