<?php

declare(strict_types=1);

namespace SensorsWave\Tests\Storage;

use PHPUnit\Framework\TestCase;
use SensorsWave\Storage\LocalFileEventQueue;

final class LocalFileEventQueueTest extends TestCase
{
    private string $queuePath;
    private string $claimDir;

    protected function setUp(): void
    {
        $suffix = uniqid('', true);
        $this->queuePath = sys_get_temp_dir() . '/sensorswave-event-queue-' . $suffix . '.json';
        $this->claimDir = sys_get_temp_dir() . '/sensorswave-event-claims-' . $suffix;
    }

    protected function tearDown(): void
    {
        @unlink($this->queuePath);
        if (is_dir($this->claimDir)) {
            $entries = scandir($this->claimDir);
            if (is_array($entries)) {
                foreach ($entries as $entry) {
                    if ($entry === '.' || $entry === '..') {
                        continue;
                    }
                    @unlink($this->claimDir . '/' . $entry);
                }
            }
            @rmdir($this->claimDir);
        }
    }

    public function testEnqueueDequeueAckLifecycle(): void
    {
        $queue = new LocalFileEventQueue($this->queuePath, $this->claimDir);

        $queue->enqueue(['{"event":"PageView"}', '{"event":"Purchase"}']);

        $messages = $queue->dequeue(50);
        self::assertCount(2, $messages);
        self::assertSame('{"event":"PageView"}', $messages[0]->payload);
        self::assertSame('{"event":"Purchase"}', $messages[1]->payload);

        $queue->ack($messages);
        self::assertSame([], $queue->dequeue(50));
    }

    public function testNackPutsMessagesBackIntoQueue(): void
    {
        $queue = new LocalFileEventQueue($this->queuePath, $this->claimDir);

        $queue->enqueue(['{"event":"RetryEvent"}']);

        $messages = $queue->dequeue(50);
        self::assertCount(1, $messages);

        $queue->nack($messages);

        $retried = $queue->dequeue(50);
        self::assertCount(1, $retried);
        self::assertSame('{"event":"RetryEvent"}', $retried[0]->payload);
    }
}
