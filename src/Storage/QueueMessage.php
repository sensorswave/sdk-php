<?php

declare(strict_types=1);

namespace SensorsWave\Storage;

/**
 * Queue message: opaque receipt plus the original payload.
 */
final class QueueMessage
{
    public function __construct(
        public readonly string $receipt,
        public readonly string $payload,
    ) {
    }
}
