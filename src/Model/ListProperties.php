<?php

declare(strict_types=1);

namespace SensorsWave\Model;

use JsonSerializable;

/**
 * 列表型属性集合。
 */
final class ListProperties implements JsonSerializable
{
    /**
     * @param array<string, list<mixed>> $items
     */
    public function __construct(private array $items = [])
    {
    }

    /**
     * 创建新的列表属性对象。
     */
    public static function create(): self
    {
        return new self();
    }

    /**
     * 设置列表属性。原生时间值保持原类型，统一在 Event::normalize() 中归一化。
     *
     * @param list<mixed> $value
     */
    public function set(string $name, array $value): self
    {
        $this->items[$name] = $value;

        return $this;
    }

    /**
     * 导出原始数组。
     *
     * @return array<string, list<mixed>>
     */
    public function all(): array
    {
        return $this->items;
    }

    public function jsonSerialize(): array
    {
        return $this->items;
    }
}
