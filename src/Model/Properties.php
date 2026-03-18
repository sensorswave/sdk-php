<?php

declare(strict_types=1);

namespace SensorsWave\Model;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use JsonSerializable;
use Traversable;

/**
 * 事件属性集合。
 *
 * @implements IteratorAggregate<string, mixed>
 */
final class Properties implements IteratorAggregate, Countable, JsonSerializable
{
    /**
     * @param array<string, mixed> $items
     */
    public function __construct(private array $items = [])
    {
    }

    /**
     * 创建新的属性对象。
     */
    public static function create(): self
    {
        return new self();
    }

    /**
     * 从数组创建属性对象。
     *
     * @param array<string, mixed> $items
     */
    public static function fromArray(array $items): self
    {
        return new self($items);
    }

    /**
     * 设置属性值。
     */
    public function set(string $name, mixed $value): self
    {
        $this->items[$name] = $value;

        return $this;
    }

    /**
     * 合并属性集合。
     */
    public function merge(self $properties): self
    {
        foreach ($properties->items as $key => $value) {
            $this->items[$key] = $value;
        }

        return $this;
    }

    /**
     * 获取属性值。
     */
    public function get(string $name, mixed $default = null): mixed
    {
        return $this->items[$name] ?? $default;
    }

    /**
     * 判断属性是否存在。
     */
    public function has(string $name): bool
    {
        return array_key_exists($name, $this->items);
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

    public function count(): int
    {
        return count($this->items);
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items);
    }

    public function jsonSerialize(): array
    {
        return $this->items;
    }
}
