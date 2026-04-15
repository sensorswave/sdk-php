<?php

declare(strict_types=1);

namespace SensorsWave\Tests\Storage;

use PHPUnit\Framework\TestCase;
use SensorsWave\Storage\RedisEventQueue;
use SensorsWave\Tests\Support\MemoryRedisClient;

final class RedisEventQueueTest extends TestCase
{
    /**
     * enqueue → dequeue → ack 完整生命周期（单 batch）。
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
        $claimKey = '{sensorswave}:event_claim:' . $batch->batchId;
        self::assertArrayHasKey($claimKey, $redis->store);
        self::assertArrayHasKey($claimKey, $redis->ttls);
        self::assertSame(3600, $redis->ttls[$claimKey]);

        $queue->ack($batch->batchId);
        self::assertArrayNotHasKey($claimKey, $redis->store);

        // 队列已空
        self::assertNull($queue->dequeue(50));
    }

    /**
     * dequeue 一次合并多个 enqueue 的 batch。
     */
    public function testDequeueMergesMultipleBatches(): void
    {
        $redis = new MemoryRedisClient();
        $queue = new RedisEventQueue($redis, maxBatches: 10);

        $queue->enqueue([['event' => 'PageView']]);
        $queue->enqueue([['event' => 'Click']]);
        $queue->enqueue([['event' => 'Purchase']]);

        // 队列中有 3 个 batch，dequeue 应一次全部取出合并
        $batch = $queue->dequeue(50);
        self::assertNotNull($batch);
        self::assertCount(3, $batch->events);
        self::assertSame('PageView',  $batch->events[0]['event']);
        self::assertSame('Click',     $batch->events[1]['event']);
        self::assertSame('Purchase',  $batch->events[2]['event']);

        $queue->ack($batch->batchId);
        self::assertNull($queue->dequeue(50));
    }

    /**
     * maxItems 限制合并后的 event 总数。
     */
    public function testDequeueRespectsMaxItems(): void
    {
        $redis = new MemoryRedisClient();
        // maxBatches=10，但 maxItems=2 限制最终 event 数
        $queue = new RedisEventQueue($redis, maxBatches: 10);

        $queue->enqueue([['event' => 'A'], ['event' => 'B']]);
        $queue->enqueue([['event' => 'C'], ['event' => 'D']]);

        $batch = $queue->dequeue(2);
        self::assertNotNull($batch);
        self::assertCount(2, $batch->events);
        self::assertSame('A', $batch->events[0]['event']);
        self::assertSame('B', $batch->events[1]['event']);
    }

    /**
     * nack 将 batch 重新放回队列头部，可再次 dequeue。
     */
    public function testNackRequeuesEvents(): void
    {
        $redis = new MemoryRedisClient();
        $queue = new RedisEventQueue($redis);

        $queue->enqueue([['event' => 'First']]);
        $queue->enqueue([['event' => 'Second']]);

        $batch = $queue->dequeue(50);
        self::assertNotNull($batch);
        self::assertCount(2, $batch->events);

        $queue->nack($batch->batchId);

        // claim 应被删除
        $claimKey = '{sensorswave}:event_claim:' . $batch->batchId;
        self::assertArrayNotHasKey($claimKey, $redis->store);

        // 重新 dequeue 应能取到相同 events
        $retried = $queue->dequeue(50);
        self::assertNotNull($retried);
        self::assertCount(2, $retried->events);
        self::assertSame('First',  $retried->events[0]['event']);
        self::assertSame('Second', $retried->events[1]['event']);
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

        $claimKey = '{sensorswave}:event_claim:' . $batch->batchId;
        self::assertSame(300, $redis->ttls[$claimKey]);
    }
}
