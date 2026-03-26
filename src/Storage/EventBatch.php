<?php

declare(strict_types=1);

namespace SensorsWave\Storage;

/**
 * 待发送事件批次。
 */
final class EventBatch
{
    /**
     * @param list<array<string, mixed>> $events
     */
    public function __construct(
        public readonly string $batchId,
        public readonly array $events,
    ) {
    }
}
