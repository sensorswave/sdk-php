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
 * Collection of event properties.
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
     * Create an empty property bag.
     */
    public static function create(): self
    {
        return new self();
    }

    /**
     * Create a property bag from an associative array.
     *
     * @param array<string, mixed> $items
     */
    public static function fromArray(array $items): self
    {
        return new self($items);
    }

    /**
     * Set a property value. Native time values are kept as-is and normalized
     * inside Event::normalize().
     */
    public function set(string $name, mixed $value): self
    {
        $this->items[$name] = $value;

        return $this;
    }

    /**
     * Called by Event::normalize(): converts native time values and other
     * non-string types into the canonical ISO8601 UTC string in place.
     */
    public function normalizeInPlace(): void
    {
        foreach ($this->items as $key => $value) {
            $this->items[$key] = PropertyValueNormalizer::normalize($value);
        }
    }

    /**
     * Merge another property bag into this one.
     */
    public function merge(self $properties): self
    {
        foreach ($properties->items as $key => $value) {
            $this->items[$key] = $value;
        }

        return $this;
    }

    /**
     * Get a property value.
     */
    public function get(string $name, mixed $default = null): mixed
    {
        return $this->items[$name] ?? $default;
    }

    /**
     * Return whether the property is set.
     */
    public function has(string $name): bool
    {
        return array_key_exists($name, $this->items);
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
