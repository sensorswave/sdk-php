<?php

declare(strict_types=1);

namespace SensorsWave\Contract;

use SensorsWave\Storage\QueueMessage;

/**
 * Event queue abstraction.
 *
 * Note: ack/nack granularity is receipt-level — every message returned by the
 * same dequeue() call shares one receipt and must be acked or nacked as a
 * single batch. Partial ack will silently confirm the rest of the batch;
 * partial nack will requeue the entire batch.
 */
interface EventQueueInterface
{
    /** @param list<string> $payloads */
    public function enqueue(array $payloads): void;

    /** @return list<QueueMessage> */
    public function dequeue(int $limit): array;

    /**
     * Acknowledge that the messages were processed successfully. Messages
     * sharing one receipt must be acked together.
     *
     * @param list<QueueMessage> $messages
     */
    public function ack(array $messages): void;

    /**
     * Requeue the messages for redelivery. Messages sharing one receipt must
     * be nacked together.
     *
     * @param list<QueueMessage> $messages
     */
    public function nack(array $messages): void;
}
