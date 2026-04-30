<?php

declare(strict_types=1);

namespace SensorsWave\Model;

use JsonSerializable;
use SensorsWave\Support\PropertyValueNormalizer;

/**
 * Collection of user-property operations.
 */
final class UserPropertyOptions implements JsonSerializable
{
    /**
     * @param array<string, mixed> $items
     */
    public function __construct(private array $items = [])
    {
    }

    /**
     * Create a new empty options object.
     */
    public static function create(): self
    {
        return new self();
    }

    /**
     * Append a $set operation. Native time values are kept as-is and
     * normalized inside Event::normalize().
     */
    public function set(string $key, mixed $value): self
    {
        $this->ensureGroup('$set');
        $this->items['$set'][$key] = $value;

        return $this;
    }

    /**
     * Append a $set_once operation. Native time values are kept as-is and
     * normalized inside Event::normalize().
     */
    public function setOnce(string $key, mixed $value): self
    {
        $this->ensureGroup('$set_once');
        $this->items['$set_once'][$key] = $value;

        return $this;
    }

    /**
     * Append a $increment operation.
     */
    public function increment(string $key, int|float $value): self
    {
        $this->ensureGroup('$increment');
        $this->items['$increment'][$key] = $value;

        return $this;
    }

    /**
     * Append a $append operation.
     */
    public function append(string $key, mixed $value): self
    {
        $this->ensureListGroup('$append', $key);
        foreach ($this->normalizeListValue($value) as $item) {
            $this->items['$append'][$key][] = $item;
        }

        return $this;
    }

    /**
     * Append a $union operation. Deduplication runs in Event::normalize()
     * against normalized string values.
     */
    public function union(string $key, mixed $value): self
    {
        $this->ensureListGroup('$union', $key);
        foreach ($this->normalizeListValue($value) as $item) {
            $this->items['$union'][$key][] = $item;
        }

        return $this;
    }

    /**
     * Append a $unset operation.
     */
    public function unset(string $key): self
    {
        $this->ensureGroup('$unset');
        $this->items['$unset'][$key] = null;

        return $this;
    }

    /**
     * Mark a $delete operation.
     */
    public function delete(): self
    {
        $this->items['$delete'] = true;

        return $this;
    }

    /**
     * Return the named operation group.
     *
     * @return array<string, mixed>|list<mixed>|array<int, mixed>
     */
    public function group(string $name): array
    {
        $group = $this->items[$name] ?? [];
        return is_array($group) ? $group : [];
    }

    /**
     * Return whether the delete flag is set.
     */
    public function isDeleteSet(): bool
    {
        return ($this->items['$delete'] ?? false) === true;
    }

    /**
     * Return the raw associative array.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->items;
    }

    public function jsonSerialize(): array
    {
        return $this->items;
    }

    /**
     * 初始化字典型操作组。
     */
    private function ensureGroup(string $name): void
    {
        if (!isset($this->items[$name]) || !is_array($this->items[$name])) {
            $this->items[$name] = [];
        }
    }

    /**
     * 初始化列表型操作组。
     */
    private function ensureListGroup(string $name, string $key): void
    {
        $this->ensureGroup($name);
        if (!isset($this->items[$name][$key]) || !is_array($this->items[$name][$key])) {
            $this->items[$name][$key] = [];
        }
    }

    /**
     * 将标量或数组统一转换为列表。元素值保持原类型，统一在 Event::normalize() 中归一化。
     *
     * @return list<mixed>
     */
    private function normalizeListValue(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [$value];
    }

    /**
     * Called by Event::normalize(): recursively normalizes the values in
     * every group and deduplicates $union by final string equality.
     */
    public function normalizeInPlace(): void
    {
        foreach ($this->items as $group => $value) {
            if (!is_array($value)) {
                continue;
            }
            $this->items[$group] = PropertyValueNormalizer::normalize($value);
        }

        if (!isset($this->items['$union']) || !is_array($this->items['$union'])) {
            return;
        }
        foreach ($this->items['$union'] as $key => $list) {
            if (!is_array($list)) {
                continue;
            }
            $deduped = [];
            foreach ($list as $item) {
                if (!in_array($item, $deduped, true)) {
                    $deduped[] = $item;
                }
            }
            $this->items['$union'][$key] = $deduped;
        }
    }
}
