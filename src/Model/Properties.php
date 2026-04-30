<?php

declare(strict_types=1);

namespace SensorsWave\Model;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use JsonSerializable;
use SensorsWave\Support\PropertyValueNormalizer;
use Traversable;

/**
 * 事件属性集合。
 *
 * Values may be scalars (string, int, float, bool, \DateTimeInterface),
 * Object (nested associative array), or Object Array (indexed array of
 * associative arrays).
 *
 * Complex property input conventions (server-side limits; the SDK does
 * not validate, exceeding any of these may be silently truncated/dropped
 * by the server):
 *  - any string value: at most 1024 UTF-8 bytes
 *  - OBJECT_ARRAY (list whose elements are associative arrays):
 *    at most 100 elements
 *
 * See README "Complex Property Input Conventions" for details.
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
     * 设置属性值。原生时间值保持原类型，统一在 Event::normalize() 中归一化。
     */
    public function set(string $name, mixed $value): self
    {
        $this->items[$name] = $value;

        return $this;
    }

    /**
     * 在 Event::normalize() 中由事件归一化流程调用：就地把原生时间值等
     * 非字符串类型转换为统一的 ISO8601 UTC 字符串。
     */
    public function normalizeInPlace(): void
    {
        foreach ($this->items as $key => $value) {
            $this->items[$key] = PropertyValueNormalizer::normalize($value);
        }
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
