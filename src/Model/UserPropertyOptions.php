<?php

declare(strict_types=1);

namespace SensorsWave\Model;

use JsonSerializable;

/**
 * 用户属性操作集合。
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
     * 创建新的用户属性操作对象。
     */
    public static function create(): self
    {
        return new self();
    }

    /**
     * 追加 $set 操作。
     */
    public function set(string $key, mixed $value): self
    {
        $this->ensureGroup('$set');
        $this->items['$set'][$key] = $value;

        return $this;
    }

    /**
     * 追加 $set_once 操作。
     */
    public function setOnce(string $key, mixed $value): self
    {
        $this->ensureGroup('$set_once');
        $this->items['$set_once'][$key] = $value;

        return $this;
    }

    /**
     * 追加 $increment 操作。
     */
    public function increment(string $key, int|float $value): self
    {
        $this->ensureGroup('$increment');
        $this->items['$increment'][$key] = $value;

        return $this;
    }

    /**
     * 追加 $append 操作。
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
     * 追加 $union 操作并去重。
     */
    public function union(string $key, mixed $value): self
    {
        $this->ensureListGroup('$union', $key);
        foreach ($this->normalizeListValue($value) as $item) {
            if (!in_array($item, $this->items['$union'][$key], true)) {
                $this->items['$union'][$key][] = $item;
            }
        }

        return $this;
    }

    /**
     * 追加 $unset 操作。
     */
    public function unset(string $key): self
    {
        $this->ensureGroup('$unset');
        $this->items['$unset'][$key] = null;

        return $this;
    }

    /**
     * 标记 $delete 操作。
     */
    public function delete(): self
    {
        $this->items['$delete'] = true;

        return $this;
    }

    /**
     * 返回指定操作组。
     *
     * @return array<string, mixed>|list<mixed>|array<int, mixed>
     */
    public function group(string $name): array
    {
        $group = $this->items[$name] ?? [];
        return is_array($group) ? $group : [];
    }

    /**
     * 判断是否设置了删除标记。
     */
    public function isDeleteSet(): bool
    {
        return ($this->items['$delete'] ?? false) === true;
    }

    /**
     * 导出原始数组。
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
     * 将标量或数组统一转换为列表。
     *
     * @return list<mixed>
     */
    private function normalizeListValue(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [$value];
    }
}
