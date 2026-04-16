<?php

declare(strict_types=1);

namespace SensorsWave\Storage;

use JsonException;
use RuntimeException;
use SensorsWave\Contract\EventQueueInterface;
use SensorsWave\Contract\RedisClientInterface;

/**
 * 基于 Redis 的事件队列。
 */
final class RedisEventQueue implements EventQueueInterface
{
    /**
     * 默认 claim TTL：1 小时。
     */
    private const DEFAULT_CLAIM_TTL_SECONDS = 3600;

    /**
     * Lua: 原子 dequeue。
     * KEYS[1] = queueKey, KEYS[2] = claimKey
     * ARGV[1] = limit, ARGV[2] = claimTtlSeconds
     */
    private const LUA_DEQUEUE = <<<'LUA'
        local items = redis.call('LRANGE', KEYS[1], 0, ARGV[1] - 1)
        if #items == 0 then
            return false
        end
        redis.call('LTRIM', KEYS[1], ARGV[1], -1)
        local payload = cjson.encode(items)
        redis.call('SETEX', KEYS[2], ARGV[2], payload)
        return payload
        LUA;

    /**
     * Lua: 原子 nack，将 payloads 逐条推回队列头部（保持原顺序）。
     * KEYS[1] = claimKey, KEYS[2] = queueKey
     */
    private const LUA_NACK = <<<'LUA'
        local payload = redis.call('GET', KEYS[1])
        if not payload then
            return 0
        end
        local items = cjson.decode(payload)
        for i = #items, 1, -1 do
            redis.call('LPUSH', KEYS[2], items[i])
        end
        redis.call('DEL', KEYS[1])
        return 1
        LUA;

    public function __construct(
        private readonly RedisClientInterface $redis,
        private readonly string $queueKey = '{sensorswave}:event_queue',
        private readonly string $claimPrefix = '{sensorswave}:event_claim:',
        private readonly int $claimTtlSeconds = self::DEFAULT_CLAIM_TTL_SECONDS,
    ) {
    }

    public function enqueue(array $payloads): void
    {
        if ($payloads === []) {
            return;
        }
        $this->redis->rPush($this->queueKey, ...$payloads);
    }

    public function dequeue(int $limit): array
    {
        $limit = max(1, $limit);

        $receipt  = uniqid('rcpt-', true);
        $claimKey = $this->claimPrefix . $receipt;

        /** @var string|false $payload */
        $payload = $this->redis->eval(
            self::LUA_DEQUEUE,
            [$this->queueKey, $claimKey],
            [$limit, $this->claimTtlSeconds],
        );

        if (!is_string($payload) || $payload === '') {
            return [];
        }

        try {
            /** @var list<string> $items */
            $items = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('failed to decode claim payload', 0, $exception);
        }

        $messages = [];
        foreach ($items as $item) {
            $messages[] = new QueueMessage($receipt, $item);
        }

        return $messages;
    }

    public function ack(array $messages): void
    {
        $receipts = array_unique(array_map(
            fn(QueueMessage $m) => $m->receipt,
            $messages,
        ));
        foreach ($receipts as $receipt) {
            $this->redis->del($this->claimPrefix . $receipt);
        }
    }

    public function nack(array $messages): void
    {
        $receipts = array_unique(array_map(
            fn(QueueMessage $m) => $m->receipt,
            $messages,
        ));
        foreach ($receipts as $receipt) {
            $this->redis->eval(
                self::LUA_NACK,
                [$this->claimPrefix . $receipt, $this->queueKey],
            );
        }
    }
}
