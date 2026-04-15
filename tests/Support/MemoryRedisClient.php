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

    public function lRange(string $key, int $start, int $stop): array
    {
        $list = $this->lists[$key] ?? [];
        $len  = count($list);
        if ($len === 0) {
            return [];
        }
        $stop = $stop < 0 ? $len + $stop : $stop;
        return array_values(array_slice($list, $start, $stop - $start + 1));
    }

    public function lTrim(string $key, int $start, int $stop): bool
    {
        $list = $this->lists[$key] ?? [];
        $len  = count($list);
        if ($len === 0) {
            return true;
        }
        $stop = $stop < 0 ? $len + $stop : $stop;
        $this->lists[$key] = array_values(array_slice($list, $start, $stop - $start + 1));
        return true;
    }

    public function eval(string $script, array $keys = [], array $args = []): mixed
    {
        // 模拟 LUA_DEQUEUE: LRANGE + LTRIM + SETEX
        if (str_contains($script, 'LRANGE') && str_contains($script, 'LTRIM')) {
            $maxBatches = (int) $args[0];
            $ttl        = (int) $args[1];
            $items      = $this->lRange($keys[0], 0, $maxBatches - 1);
            if ($items === []) {
                return false;
            }
            $this->lTrim($keys[0], $maxBatches, -1);
            $payload = json_encode($items);
            $this->setEx($keys[1], $payload, $ttl);
            return $payload;
        }

        // 模拟 LUA_NACK: GET KEYS[0] → LPUSH items 逐条 → DEL KEYS[0]
        if (str_contains($script, 'cjson.decode') && str_contains($script, 'LPUSH')) {
            $payload = $this->get($keys[0]);
            if ($payload === null) {
                return 0;
            }
            $items = json_decode($payload, true);
            if (is_array($items)) {
                for ($i = count($items) - 1; $i >= 0; $i--) {
                    $this->lPush($keys[1], $items[$i]);
                }
            }
            $this->del($keys[0]);
            return 1;
        }

        return false;
    }
}
