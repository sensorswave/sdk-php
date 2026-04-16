<?php

declare(strict_types=1);

namespace SensorsWave\Tests\Support;

use SensorsWave\Contract\EventQueueInterface;
use SensorsWave\Storage\QueueMessage;

final class MemoryEventQueue implements EventQueueInterface
{
    /** @var list<string> */
    public array $queued = [];
    /** @var list<QueueMessage> */
    public array $claimed = [];

    public function enqueue(array $payloads): void
    {
        array_push($this->queued, ...$payloads);
    }

    public function dequeue(int $limit): array
    {
        if ($this->queued === []) {
            return [];
        }

        $taken = array_splice($this->queued, 0, max(1, $limit));
        $messages = [];
        foreach ($taken as $payload) {
            $messages[] = new QueueMessage(uniqid('rcpt-', true), $payload);
        }
        array_push($this->claimed, ...$messages);

        return $messages;
    }

    public function ack(array $messages): void
    {
        $receipts = array_map(fn(QueueMessage $m) => $m->receipt, $messages);
        $this->claimed = array_values(array_filter(
            $this->claimed,
            fn(QueueMessage $m) => !in_array($m->receipt, $receipts, true),
        ));
    }

    public function nack(array $messages): void
    {
        $receipts = [];
        foreach (array_reverse($messages) as $message) {
            $receipts[] = $message->receipt;
            array_unshift($this->queued, $message->payload);
        }
        $this->claimed = array_values(array_filter(
            $this->claimed,
            fn(QueueMessage $m) => !in_array($m->receipt, $receipts, true),
        ));
    }
}
