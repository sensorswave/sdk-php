<?php

declare(strict_types=1);

namespace SensorsWave\Contract;

use SensorsWave\Storage\QueueMessage;

/**
 * 事件队列抽象。
 */
interface EventQueueInterface
{
    /** @param list<string> $payloads */
    public function enqueue(array $payloads): void;

    /** @return list<QueueMessage> */
    public function dequeue(int $limit): array;

    /** @param list<QueueMessage> $messages */
    public function ack(array $messages): void;

    /** @param list<QueueMessage> $messages */
    public function nack(array $messages): void;
}
