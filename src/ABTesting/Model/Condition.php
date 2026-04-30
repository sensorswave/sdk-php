<?php

declare(strict_types=1);

namespace SensorsWave\ABTesting\Model;

/**
 * A/B rule condition.
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
     * Build a condition from a decoded payload array.
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

    /**
     * Export as an associative array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'field_class' => $this->fieldClass,
            'field' => $this->field,
            'opt' => $this->operator,
            'value' => $this->value,
        ];
    }
}
