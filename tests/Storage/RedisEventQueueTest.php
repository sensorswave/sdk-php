<?php

declare(strict_types=1);

namespace SensorsWave\Tests\Storage;

use PHPUnit\Framework\TestCase;
use SensorsWave\Storage\RedisEventQueue;
use SensorsWave\Tests\Support\MemoryRedisClient;

final class RedisEventQueueTest extends TestCase
{
    /**
     * enqueue → dequeue → ack 完整生命周期。
     */
    public function testEnqueueDequeueAckLifecycle(): void
    {
        $redis = new MemoryRedisClient();
        $queue = new RedisEventQueue($redis);

        $queue->enqueue([['event' => 'Purchase', 'amount' => 10]]);

        $batch = $queue->dequeue(50);
        self::assertNotNull($batch);
        self::assertCount(1, $batch->events);
        self::assertSame('Purchase', $batch->events[0]['event']);

        // claim 应存在且有 TTL
        $claimKey = 'sensorswave:event_claim:' . $batch->batchId;
        self::assertArrayHasKey($claimKey, $redis->store);
        self::assertArrayHasKey($claimKey, $redis->ttls);
        self::assertSame(3600, $redis->ttls[$claimKey]);

        $queue->ack($batch->batchId);
        self::assertArrayNotHasKey($claimKey, $redis->store);

        // 队列已空
        self::assertNull($queue->dequeue(50));
    }

    /**
     * nack 将 batch 重新放回队列头部。
     */
    public function testNackRequeuesEvents(): void
    {
        $redis = new MemoryRedisClient();
        $queue = new RedisEventQueue($redis);

        $queue->enqueue([['event' => 'First']]);
        $batch = $queue->dequeue(50);
        self::assertNotNull($batch);

        $queue->nack($batch->batchId);

        // claim 应被删除
        $claimKey = 'sensorswave:event_claim:' . $batch->batchId;
        self::assertArrayNotHasKey($claimKey, $redis->store);

        // 重新 dequeue 应能取到
        $retried = $queue->dequeue(50);
        self::assertNotNull($retried);
        self::assertSame('First', $retried->events[0]['event']);
    }

    /**
     * claim TTL 可通过构造参数自定义。
     */
    public function testCustomClaimTtl(): void
    {
        $redis = new MemoryRedisClient();
        $queue = new RedisEventQueue($redis, claimTtlSeconds: 300);

        $queue->enqueue([['event' => 'Test']]);
        $batch = $queue->dequeue(50);
        self::assertNotNull($batch);

        $claimKey = 'sensorswave:event_claim:' . $batch->batchId;
        self::assertSame(300, $redis->ttls[$claimKey]);
    }
}
