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

    public function __construct(
        private readonly RedisClientInterface $redis,
        private readonly string $queueKey = 'sensorswave:event_queue',
        private readonly string $claimPrefix = 'sensorswave:event_claim:',
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
        $payload = $this->redis->lPop($this->queueKey);
        if (!is_string($payload) || $payload === '') {
            return null;
        }

        try {
            /** @var array{batch_id?: mixed, events?: mixed} $decoded */
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('failed to decode Redis event batch', 0, $exception);
        }

        $batchId = is_string($decoded['batch_id'] ?? null)
            ? $decoded['batch_id']
            : uniqid('batch-', true);
        /** @var list<array<string, mixed>> $events */
        $events = is_array($decoded['events'] ?? null)
            ? array_slice($decoded['events'], 0, max(1, $maxItems))
            : [];

        $this->redis->setEx($this->claimPrefix . $batchId, $payload, $this->claimTtlSeconds);

        return new EventBatch($batchId, $events);
    }

    public function ack(string $batchId): void
    {
        $this->redis->del($this->claimPrefix . $batchId);
    }

    public function nack(string $batchId): void
    {
        $claimKey = $this->claimPrefix . $batchId;
        $payload = $this->redis->get($claimKey);
        if (!is_string($payload) || $payload === '') {
            return;
        }

        $this->redis->lPush($this->queueKey, $payload);
        $this->redis->del($claimKey);
    }
}
