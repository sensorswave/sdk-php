<?php

declare(strict_types=1);

namespace SensorsWave\Contract;

/**
 * Redis 客户端最小抽象，避免绑定具体扩展。
 *
 * 所有 key 建议使用 hash tag（如 {sensorswave}:xxx）以兼容 Redis Cluster。
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
     * 执行 Lua 脚本。
     *
     * @param string   $script Lua 脚本内容
     * @param list<string> $keys   KEYS 参数
     * @param list<string|int> $args   ARGV 参数
     * @return mixed 脚本返回值
     */
    public function eval(string $script, array $keys = [], array $args = []): mixed;
}
