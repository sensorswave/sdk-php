<?php

declare(strict_types=1);

namespace SensorsWave\ABTesting\Model;

/**
 * A/B 实验规格。
 */
final class ABSpec
{
    /**
     * @param array<string, list<Rule>> $rules
     * @param array<string, array<string, mixed>> $variantValues
     */
    public function __construct(
        public readonly int $id,
        public readonly string $key,
        public readonly string $name,
        public readonly int $type,
        public readonly string $traffic,
        public readonly string $subjectId,
        public readonly bool $enabled,
        public readonly bool $sticky,
        public readonly string $salt,
        public readonly int $version,
        public readonly bool $disableImpress,
        public readonly array $rules,
        public readonly array $variantValues,
    ) {
    }

    /**
     * 从数组创建规格对象。
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $rules = [];
        foreach (($data['rules'] ?? []) as $ruleType => $ruleList) {
            $rules[(string) $ruleType] = [];
            foreach ((array) $ruleList as $rule) {
                if (is_array($rule)) {
                    $rules[(string) $ruleType][] = Rule::fromArray($rule);
                }
            }
        }

        $variantValues = [];
        foreach (($data['variant_payloads'] ?? []) as $variantId => $payload) {
            if (is_array($payload)) {
                $variantValues[(string) $variantId] = $payload;
                continue;
            }

            if (is_string($payload) && $payload !== '') {
                $decoded = json_decode($payload, true);
                if (is_array($decoded)) {
                    $variantValues[(string) $variantId] = $decoded;
                }
            }
        }

        return new self(
            (int) ($data['id'] ?? 0),
            (string) ($data['key'] ?? ''),
            (string) ($data['name'] ?? ''),
            (int) ($data['typ'] ?? 0),
            (string) ($data['traffic'] ?? ''),
            (string) ($data['subject_id'] ?? ''),
            (bool) ($data['enabled'] ?? false),
            (bool) ($data['sticky'] ?? false),
            (string) ($data['salt'] ?? ''),
            (int) ($data['version'] ?? 0),
            (bool) ($data['disable_impress'] ?? false),
            $rules,
            $variantValues,
        );
    }
}
