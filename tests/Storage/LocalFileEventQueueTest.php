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

    public function testQueueEnqueueDequeueAckLifecycle(): void
    {
        $queue = new LocalFileEventQueue($this->queuePath, $this->claimDir);
        $events = [['event' => 'PageView'], ['event' => 'Purchase']];

        $queue->enqueue($events);

        $batch = $queue->dequeue(50);
        self::assertNotNull($batch);
        self::assertSame($events, $batch->events);

        $queue->ack($batch->batchId);
        self::assertNull($queue->dequeue(50));
    }

    public function testQueueNackPutsClaimedBatchBackIntoQueue(): void
    {
        $queue = new LocalFileEventQueue($this->queuePath, $this->claimDir);
        $events = [['event' => 'RetryEvent']];

        $queue->enqueue($events);

        $batch = $queue->dequeue(50);
        self::assertNotNull($batch);

        $queue->nack($batch->batchId);

        $retried = $queue->dequeue(50);
        self::assertNotNull($retried);
        self::assertSame($events, $retried->events);
    }
}
