<?php

declare(strict_types=1);

namespace SensorsWave\Tests\Worker;

use PHPUnit\Framework\TestCase;
use SensorsWave\Config\Config;
use SensorsWave\Http\Request;
use SensorsWave\Http\Response;
use SensorsWave\Http\TransportInterface;
use SensorsWave\Tests\Support\MemoryEventQueue;
use SensorsWave\Worker\SendCommand;

final class SendCommandTest extends TestCase
{
    public function testSendCommandPostsQueuedEventsAndAcknowledgesBatch(): void
    {
        $queue = new MemoryEventQueue();
        $queue->enqueue([['event' => 'Purchase', 'properties' => ['amount' => 10]]]);
        $transport = new class implements TransportInterface {
            /** @var list<Request> */
            public array $requests = [];

            public function send(Request $request): Response
            {
                $this->requests[] = $request;
                return new Response(200, '{}');
            }
        };

        $command = new SendCommand(
            'https://collector.example.com',
            'test-token',
            new Config(eventQueue: $queue),
            $transport
        );

        self::assertSame(0, $command->run());
        self::assertCount(1, $transport->requests);
        self::assertCount(0, $queue->claimed);
        self::assertNull($queue->dequeue(50));
    }

    public function testSendCommandNacksBatchWhenDeliveryFails(): void
    {
        $queue = new MemoryEventQueue();
        $queue->enqueue([['event' => 'Purchase', 'properties' => ['amount' => 10]]]);
        $transport = new class implements TransportInterface {
            public function send(Request $request): Response
            {
                return new Response(500, '{"msg":"fail"}');
            }
        };

        $command = new SendCommand(
            'https://collector.example.com',
            'test-token',
            new Config(eventQueue: $queue, httpRetry: 1),
            $transport
        );

        self::assertSame(1, $command->run());
        self::assertCount(0, $queue->claimed);
        self::assertNotNull($queue->dequeue(50));
    }
}
