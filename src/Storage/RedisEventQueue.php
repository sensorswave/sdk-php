<?php

declare(strict_types=1);

namespace SensorsWave\Storage;

use JsonException;
use RuntimeException;
use SensorsWave\Contract\EventQueueInterface;
use SensorsWave\Contract\RedisClientInterface;

final class RedisEventQueue implements EventQueueInterface
{
    /**
     * 默认 claim TTL：1 小时。如果 worker 崩溃，claim 过期后自动清理，
     * 避免永久残留。
     */
    private const DEFAULT_CLAIM_TTL_SECONDS = 3600;

    /**
     * Lua: 原子 dequeue。
     * KEYS[1] = queueKey, KEYS[2] = claimKey（调用时动态拼接）
     * ARGV[1] = claimTtlSeconds
     * 返回 payload 字符串或 false。
     */
    private const LUA_DEQUEUE = <<<'LUA'
        local payload = redis.call('LPOP', KEYS[1])
        if not payload then
            return false
        end
        redis.call('SETEX', KEYS[2], ARGV[1], payload)
        return payload
        LUA;

    /**
     * Lua: 原子 nack。
     * KEYS[1] = claimKey, KEYS[2] = queueKey
     * 返回 1（成功）或 0（claim 已过期）。
     */
    private const LUA_NACK = <<<'LUA'
        local payload = redis.call('GET', KEYS[1])
        if not payload then
            return 0
        end
        redis.call('LPUSH', KEYS[2], payload)
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

    public function enqueue(array $events): void
    {
        try {
            $payload = json_encode([
                'batch_id' => uniqid('batch-', true),
                'events' => $events,
            ], JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('failed to encode Redis event batch', 0, $exception);
        }

        $this->redis->rPush($this->queueKey, $payload);
    }

    public function dequeue(int $maxItems): ?EventBatch
    {
        $batchId = uniqid('batch-', true);
        $claimKey = $this->claimPrefix . $batchId;

        /** @var string|false $payload */
        $payload = $this->redis->eval(
            self::LUA_DEQUEUE,
            [$this->queueKey, $claimKey],
            [$this->claimTtlSeconds],
        );

        if (!is_string($payload) || $payload === '') {
            return null;
        }

        try {
            /** @var array{batch_id?: mixed, events?: mixed} $decoded */
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('failed to decode Redis event batch', 0, $exception);
        }

        /** @var list<array<string, mixed>> $events */
        $events = is_array($decoded['events'] ?? null)
            ? array_slice($decoded['events'], 0, max(1, $maxItems))
            : [];

        return new EventBatch($batchId, $events);
    }

    public function ack(string $batchId): void
    {
        $this->redis->del($this->claimPrefix . $batchId);
    }

    public function nack(string $batchId): void
    {
        $claimKey = $this->claimPrefix . $batchId;

        $this->redis->eval(
            self::LUA_NACK,
            [$claimKey, $this->queueKey],
        );
    }
}
