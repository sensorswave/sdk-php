<?php

declare(strict_types=1);

namespace SensorsWave\Tests\Support;

use SensorsWave\Contract\EventQueueInterface;
use SensorsWave\Storage\EventBatch;

final class MemoryEventQueue implements EventQueueInterface
{
    /** @var list<EventBatch> */
    public array $queued = [];
    /** @var array<string, EventBatch> */
    public array $claimed = [];

    public function enqueue(array $events): void
    {
        $this->queued[] = new EventBatch(uniqid('batch-', true), $events);
    }

    public function dequeue(int $maxItems): ?EventBatch
    {
        if ($this->queued === []) {
            return null;
        }

        $batch = array_shift($this->queued);
        $trimmed = new EventBatch($batch->batchId, array_slice($batch->events, 0, max(1, $maxItems)));
        $this->claimed[$trimmed->batchId] = $trimmed;

        return $trimmed;
    }

    public function ack(string $batchId): void
    {
        unset($this->claimed[$batchId]);
    }

    public function nack(string $batchId): void
    {
        if (!isset($this->claimed[$batchId])) {
            return;
        }

        array_unshift($this->queued, $this->claimed[$batchId]);
        unset($this->claimed[$batchId]);
    }
}
