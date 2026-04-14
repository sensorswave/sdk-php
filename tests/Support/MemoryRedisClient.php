<?php

declare(strict_types=1);

namespace SensorsWave\Tests\Support;

use SensorsWave\Contract\RedisClientInterface;

/**
 * 内存 Redis 客户端测试替身。
 */
final class MemoryRedisClient implements RedisClientInterface
{
    /** @var array<string, string> */
    public array $store = [];
    /** @var array<string, list<string>> */
    public array $lists = [];
    /** @var array<string, int> 记录 setEx 调用时的 TTL */
    public array $ttls = [];

    public function get(string $key): string|null
    {
        return $this->store[$key] ?? null;
    }

    public function set(string $key, string $value): bool
    {
        $this->store[$key] = $value;
        return true;
    }

    public function setEx(string $key, string $value, int $ttlSeconds): bool
    {
        $this->store[$key] = $value;
        $this->ttls[$key] = $ttlSeconds;
        return true;
    }

    public function del(string ...$keys): int
    {
        $deleted = 0;
        foreach ($keys as $key) {
            if (isset($this->store[$key])) {
                unset($this->store[$key], $this->ttls[$key]);
                $deleted++;
            }
        }
        return $deleted;
    }

    public function lPush(string $key, string ...$values): int
    {
        if (!isset($this->lists[$key])) {
            $this->lists[$key] = [];
        }
        foreach (array_reverse($values) as $value) {
            array_unshift($this->lists[$key], $value);
        }
        return count($this->lists[$key]);
    }

    public function rPush(string $key, string ...$values): int
    {
        if (!isset($this->lists[$key])) {
            $this->lists[$key] = [];
        }
        foreach ($values as $value) {
            $this->lists[$key][] = $value;
        }
        return count($this->lists[$key]);
    }

    public function lPop(string $key): string|null
    {
        if (!isset($this->lists[$key]) || $this->lists[$key] === []) {
            return null;
        }
        return array_shift($this->lists[$key]);
    }
}
