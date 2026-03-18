<?php

declare(strict_types=1);

namespace SensorsWave\ABTesting\Model;

/**
 * A/B 规则定义。
 */
final class Rule
{
    /**
     * @param list<Condition> $conditions
     */
    public function __construct(
        public readonly string $name,
        public readonly string $id,
        public readonly string $salt,
        public readonly float $rollout,
        public readonly array $conditions,
        public readonly ?string $override,
    ) {
    }

    /**
     * 从数组创建规则对象。
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $conditions = [];
        foreach (($data['conditions'] ?? []) as $condition) {
            if (is_array($condition)) {
                $conditions[] = Condition::fromArray($condition);
            }
        }

        $override = $data['override'] ?? null;
        if ($override !== null && !is_string($override)) {
            $override = (string) $override;
        }

        return new self(
            (string) ($data['name'] ?? ''),
            (string) ($data['id'] ?? ''),
            (string) ($data['salt'] ?? ''),
            (float) ($data['rollout'] ?? 0),
            $conditions,
            $override,
        );
    }
}
