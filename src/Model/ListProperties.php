<?php

declare(strict_types=1);

namespace SensorsWave\Model;

use JsonSerializable;

/**
 * List-shaped property collection used by profileAppend / profileUnion.
 *
 * Each value must be a list of scalars. Object (associative array) and
 * Object Array (indexed array of associative arrays) values are **not
 * accepted**: the SDK does not reject them, but the server will infer
 * them as OBJECT_ARRAY, which contradicts list semantics.
 *
 * See README "Complex Property Input Conventions" for details.
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
     * Create an empty list-property bag.
     */
    public static function create(): self
    {
        return new self();
    }

    /**
     * Set a list-shaped property. Native time values are kept as-is and
     * normalized inside Event::normalize().
     *
     * @param list<mixed> $value
     */
    public function set(string $name, array $value): self
    {
        $this->items[$name] = $value;

        return $this;
    }

    /**
     * Return the raw associative array.
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
