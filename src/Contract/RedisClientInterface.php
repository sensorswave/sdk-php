<?php

declare(strict_types=1);

namespace SensorsWave\Contract;

/**
 * Redis 客户端最小抽象，避免绑定具体扩展。
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
}
