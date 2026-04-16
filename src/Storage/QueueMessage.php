<?php

declare(strict_types=1);

namespace SensorsWave\Storage;

/**
 * 队列消息：不透明 receipt + 原始 payload。
 */
final class QueueMessage
{
    public function __construct(
        public readonly string $receipt,
        public readonly string $payload,
    ) {
    }
}
