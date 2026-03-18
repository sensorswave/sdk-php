<?php

declare(strict_types=1);

namespace SensorsWave\ABTesting\Model;

/**
 * A/B 规则条件。
 */
final class Condition
{
    public function __construct(
        public readonly string $fieldClass,
        public readonly string $field,
        public readonly string $operator,
        public readonly mixed $value,
    ) {
    }

    /**
     * 从数组创建条件对象。
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['field_class'] ?? ''),
            (string) ($data['field'] ?? ''),
            (string) ($data['opt'] ?? ''),
            $data['value'] ?? null,
        );
    }
}
