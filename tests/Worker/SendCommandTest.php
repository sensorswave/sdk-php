<?php

declare(strict_types=1);

namespace SensorsWave\Tests\Worker;

use PHPUnit\Framework\TestCase;
use SensorsWave\Config\Config;
use SensorsWave\Contract\LoggerInterface;
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

    /**
     * json_encode 失败时应 ack 该 batch（丢弃不可序列化数据）并继续处理。
     */
    public function testSendCommandAcksAndContinuesWhenJsonEncodeFails(): void
    {
        $queue = new MemoryEventQueue();
        // NAN 无法被 json_encode 序列化，会触发 JsonException
        $queue->enqueue([['value' => NAN]]);
        // 第二个 batch 是合法的
        $queue->enqueue([['event' => 'ValidEvent']]);

        $transport = new class implements TransportInterface {
            /** @var list<Request> */
            public array $requests = [];

            public function send(Request $request): Response
            {
                $this->requests[] = $request;
                return new Response(200, '{}');
            }
        };

        $logger = new class implements LoggerInterface {
            /** @var list<string> */
            public array $errors = [];
            public function debug(string $message, mixed ...$context): void {}
            public function info(string $message, mixed ...$context): void {}
            public function warn(string $message, mixed ...$context): void {}
            public function error(string $message, mixed ...$context): void
            {
                $this->errors[] = $message;
            }
        };

        $command = new SendCommand(
            'https://collector.example.com',
            'test-token',
            new Config(logger: $logger, eventQueue: $queue),
            $transport
        );

        $status = $command->run();

        // 不可序列化的 batch 被 ack（丢弃），合法的 batch 正常发送
        self::assertSame(1, $status);
        self::assertCount(1, $transport->requests);
        self::assertCount(0, $queue->claimed);
        self::assertNull($queue->dequeue(50));
        self::assertNotEmpty($logger->errors);
        self::assertStringContainsString('failed to encode', $logger->errors[0]);
    }
}
