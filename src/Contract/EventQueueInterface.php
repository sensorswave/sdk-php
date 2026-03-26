<?php

declare(strict_types=1);

namespace SensorsWave\Contract;

use SensorsWave\Storage\EventBatch;

/**
 * 待发送事件队列抽象。
 */
interface EventQueueInterface
{
    /**
     * @param list<array<string, mixed>> $events
     */
    public function enqueue(array $events): void;

    public function dequeue(int $maxItems): ?EventBatch;

    public function ack(string $batchId): void;

    public function nack(string $batchId): void;
}
