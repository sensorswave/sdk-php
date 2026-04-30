<?php

declare(strict_types=1);

namespace SensorsWave\Contract;

/**
 * Minimal Redis client abstraction so the SDK does not depend on a specific
 * extension.
 *
 * All keys should use a hash tag (e.g. {sensorswave}:xxx) for Redis Cluster
 * compatibility.
 */
interface RedisClientInterface
{
    public function get(string $key): string|false|null;

    public function set(string $key, string $value): bool;

    public function setEx(string $key, string $value, int $ttlSeconds): bool;

    public function del(string ...$keys): int;

    public function lPush(string $key, string ...$values): int;

    public function rPush(string $key, string ...$values): int;

    public function lPop(string $key): string|false|null;

    /**
     * @return list<string>
     */
    public function lRange(string $key, int $start, int $stop): array;

    public function lTrim(string $key, int $start, int $stop): bool;

    /**
     * Run a Lua script.
     *
     * @param string           $script Lua script source
     * @param list<string>     $keys   KEYS arguments
     * @param list<string|int> $args   ARGV arguments
     * @return mixed Script return value
     */
    public function eval(string $script, array $keys = [], array $args = []): mixed;
}
