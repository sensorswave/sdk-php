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
     * Lua: 原子批量 dequeue。
     * KEYS[1] = queueKey, KEYS[2] = claimKey
     * ARGV[1] = maxBatches（一次最多取几个 batch）, ARGV[2] = claimTtlSeconds
     * 返回 JSON 编码的 batch payload 数组字符串，或 false（队列为空）。
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
     * Lua: 原子 nack，将 batch payloads 逐条推回队列头部（保持原顺序）。
     * KEYS[1] = claimKey, KEYS[2] = queueKey
     * 返回 1（成功）或 0（claim 已过期）。
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

    /**
     * 一次 dequeue 最多取几个 batch 合并。
     */
    private const DEFAULT_MAX_BATCHES = 10;

    public function __construct(
        private readonly RedisClientInterface $redis,
        private readonly string $queueKey = '{sensorswave}:event_queue',
        private readonly string $claimPrefix = '{sensorswave}:event_claim:',
        private readonly int $claimTtlSeconds = self::DEFAULT_CLAIM_TTL_SECONDS,
        private readonly int $maxBatches = self::DEFAULT_MAX_BATCHES,
    ) {
    }

    /**
     * 将 events 打包成一个 batch（含 batch_id 信封）后 RPUSH 进队列。
     */
    public function enqueue(array $events): void
    {
        try {
            $payload = json_encode([
                'batch_id' => uniqid('batch-', true),
                'events'   => $events,
            ], JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('failed to encode Redis event batch', 0, $exception);
        }

        $this->redis->rPush($this->queueKey, $payload);
    }

    /**
     * 原子批量取出最多 $maxBatches 个 batch，解包后合并 events，
     * 截取前 $maxItems 条返回。
     */
    public function dequeue(int $maxItems): ?EventBatch
    {
        $batchId  = uniqid('batch-', true);
        $claimKey = $this->claimPrefix . $batchId;

        /** @var string|false $payload */
        $payload = $this->redis->eval(
            self::LUA_DEQUEUE,
            [$this->queueKey, $claimKey],
            [$this->maxBatches, $this->claimTtlSeconds],
        );

        if (!is_string($payload) || $payload === '') {
            return null;
        }

        try {
            /** @var list<string> $rawBatches */
            $rawBatches = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('failed to decode claim payload', 0, $exception);
        }

        $events = [];
        foreach ($rawBatches as $raw) {
            try {
                /** @var array{batch_id?: mixed, events?: mixed} $decoded */
                $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decoded['events'] ?? null)) {
                    foreach ($decoded['events'] as $event) {
                        $events[] = $event;
                        if (count($events) >= $maxItems) {
                            break 2;
                        }
                    }
                }
            } catch (JsonException) {
                // 跳过损坏的 batch
            }
        }

        if ($events === []) {
            $this->redis->del($claimKey);
            return null;
        }

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
