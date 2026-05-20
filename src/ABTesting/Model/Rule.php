<?php

declare(strict_types=1);

namespace SensorsWave\ABTesting\Model;

/**
 * A/B rule definition.
 */
final class Rule
{
    /**
     * @param list<Condition> $conditions
     * @param list<VariantGroup> $variantGroup
     */
    public function __construct(
        public readonly string $name,
        public readonly string $id,
        public readonly string $salt,
        public readonly float $rollout,
        public readonly array $conditions,
        public readonly ?string $override,
        public readonly string $decisionRuleId = '',
        public readonly array $variantGroup = [],
    ) {
    }

    /**
     * Build a rule from a decoded payload array.
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

        $variantGroup = [];
        foreach (($data['variant_group'] ?? []) as $group) {
            if (is_array($group)) {
                $variantGroup[] = VariantGroup::fromArray($group);
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
            (string) ($data['decision_rule_id'] ?? ''),
            $variantGroup,
        );
    }

    /**
     * Export as an associative array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [
            'name' => $this->name,
            'id' => $this->id,
            'salt' => $this->salt,
            'rollout' => $this->rollout,
            'conditions' => array_map(
                static fn (Condition $condition): array => $condition->toArray(),
                $this->conditions
            ),
            'override' => $this->override,
        ];

        if ($this->decisionRuleId !== '') {
            $payload['decision_rule_id'] = $this->decisionRuleId;
        }

        if ($this->variantGroup !== []) {
            $payload['variant_group'] = array_map(
                static fn (VariantGroup $group): array => $group->toArray(),
                $this->variantGroup
            );
        }

        return $payload;
    }
}
