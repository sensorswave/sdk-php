<?php

declare(strict_types=1);

namespace SensorsWave\Tests\Storage;

use PHPUnit\Framework\TestCase;
use SensorsWave\Storage\RedisEventQueue;
use SensorsWave\Tests\Support\MemoryRedisClient;

final class RedisEventQueueTest extends TestCase
{
    public function testEnqueueDequeueAckLifecycle(): void
    {
        $redis = new MemoryRedisClient();
        $queue = new RedisEventQueue($redis);

        $queue->enqueue(['{"event":"Purchase","amount":10}']);

        $messages = $queue->dequeue(50);
        self::assertCount(1, $messages);
        self::assertSame('{"event":"Purchase","amount":10}', $messages[0]->payload);

        $claimKey = '{sensorswave}:event_claim:' . $messages[0]->receipt;
        self::assertArrayHasKey($claimKey, $redis->store);
        self::assertArrayHasKey($claimKey, $redis->ttls);
        self::assertSame(3600, $redis->ttls[$claimKey]);

        $queue->ack($messages);
        self::assertArrayNotHasKey($claimKey, $redis->store);

        self::assertSame([], $queue->dequeue(50));
    }

    public function testDequeueMultipleMessages(): void
    {
        $redis = new MemoryRedisClient();
        $queue = new RedisEventQueue($redis);

        $queue->enqueue(['{"event":"A"}', '{"event":"B"}', '{"event":"C"}']);

        $messages = $queue->dequeue(50);
        self::assertCount(3, $messages);
        self::assertSame('{"event":"A"}', $messages[0]->payload);
        self::assertSame('{"event":"B"}', $messages[1]->payload);
        self::assertSame('{"event":"C"}', $messages[2]->payload);

        $queue->ack($messages);
        self::assertSame([], $queue->dequeue(50));
    }

    public function testDequeueRespectsLimit(): void
    {
        $redis = new MemoryRedisClient();
        $queue = new RedisEventQueue($redis);

        $queue->enqueue(['{"event":"A"}', '{"event":"B"}', '{"event":"C"}', '{"event":"D"}']);

        $messages = $queue->dequeue(2);
        self::assertCount(2, $messages);
        self::assertSame('{"event":"A"}', $messages[0]->payload);
        self::assertSame('{"event":"B"}', $messages[1]->payload);

        $remaining = $queue->dequeue(50);
        self::assertCount(2, $remaining);
        self::assertSame('{"event":"C"}', $remaining[0]->payload);
        self::assertSame('{"event":"D"}', $remaining[1]->payload);
    }

    public function testNackRequeuesMessages(): void
    {
        $redis = new MemoryRedisClient();
        $queue = new RedisEventQueue($redis);

        $queue->enqueue(['{"event":"First"}', '{"event":"Second"}']);

        $messages = $queue->dequeue(50);
        self::assertCount(2, $messages);

        $queue->nack($messages);

        $claimKey = '{sensorswave}:event_claim:' . $messages[0]->receipt;
        self::assertArrayNotHasKey($claimKey, $redis->store);

        $retried = $queue->dequeue(50);
        self::assertCount(2, $retried);
        self::assertSame('{"event":"First"}', $retried[0]->payload);
        self::assertSame('{"event":"Second"}', $retried[1]->payload);
    }

    public function testCustomClaimTtl(): void
    {
        $redis = new MemoryRedisClient();
        $queue = new RedisEventQueue($redis, claimTtlSeconds: 300);

        $queue->enqueue(['{"event":"Test"}']);
        $messages = $queue->dequeue(50);
        self::assertCount(1, $messages);

        $claimKey = '{sensorswave}:event_claim:' . $messages[0]->receipt;
        self::assertSame(300, $redis->ttls[$claimKey]);
    }
}
