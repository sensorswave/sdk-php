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
    public function __construct(
        private readonly RedisClientInterface $redis,
        private readonly string $queueKey = 'sensorswave:event_queue',
        private readonly string $claimPrefix = 'sensorswave:event_claim:',
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

        $this->redis->set($this->claimPrefix . $batchId, $payload);

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
