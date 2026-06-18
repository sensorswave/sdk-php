<?php

declare(strict_types=1);

namespace SensorsWave\Tests\Worker;

use PHPUnit\Framework\TestCase;
use SensorsWave\Config\Config;
use SensorsWave\Http\ConcurrentTransportInterface;
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
        $queue->enqueue(['{"event":"Purchase","properties":{"amount":10}}']);
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
        self::assertSame([], $queue->dequeue(50));
    }

    public function testSendCommandNacksBatchWhenDeliveryFails(): void
    {
        $queue = new MemoryEventQueue();
        $queue->enqueue(['{"event":"Purchase","properties":{"amount":10}}']);
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
        self::assertNotSame([], $queue->dequeue(50));
    }

    public function testSendCommandDoesNotRetryUnauthorized(): void
    {
        $queue = new MemoryEventQueue();
        $queue->enqueue(['{"event":"Unauthorized"}']);
        $transport = new class implements TransportInterface {
            /** @var list<Request> */
            public array $requests = [];
            /** @var list<int> */
            private array $statuses = [401, 200];

            public function send(Request $request): Response
            {
                $this->requests[] = $request;
                return new Response(array_shift($this->statuses) ?? 200, '{"msg":"unauthorized"}');
            }
        };

        $command = new SendCommand(
            'https://collector.example.com',
            'test-token',
            new Config(eventQueue: $queue, httpRetry: 2),
            $transport
        );

        self::assertSame(1, $command->run());
        self::assertCount(1, $transport->requests);
        self::assertSame(['{"event":"Unauthorized"}'], $queue->queued);
        self::assertCount(0, $queue->claimed);
    }

    /**
     * 多条消息应在同一次 dequeue 中全部取出并成功发送。
     */
    public function testSendCommandProcessesAllQueuedMessages(): void
    {
        $queue = new MemoryEventQueue();
        $queue->enqueue(['{"event":"First"}', '{"event":"Second"}']);

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

        $status = $command->run();

        self::assertSame(0, $status);
        self::assertCount(1, $transport->requests);
        self::assertCount(0, $queue->claimed);
        self::assertSame([], $queue->dequeue(50));
    }

    public function testSendCommandUsesConfiguredConcurrencyForWorkerBatches(): void
    {
        $queue = new MemoryEventQueue();
        $queue->enqueue(['{"event":"First"}', '{"event":"Second"}']);
        $transport = new class implements ConcurrentTransportInterface {
            /** @var list<int> */
            public array $concurrentRequestCounts = [];

            public function send(Request $request): Response
            {
                throw new \RuntimeException('serial send should not be used');
            }

            public function sendConcurrent(array $requests, int $concurrency): array
            {
                $this->concurrentRequestCounts[] = count($requests);
                return array_map(fn(Request $request): Response => new Response(200, '{}'), $requests);
            }
        };

        $command = new SendCommand(
            'https://collector.example.com',
            'test-token',
            new Config(httpConcurrency: 2, eventQueue: $queue),
            $transport
        );

        self::assertSame(0, $command->run(1));
        self::assertSame([2], $transport->concurrentRequestCounts);
        self::assertSame([], $queue->dequeue(50));
    }

    /**
     * 超过配置阈值的发送批次应在 worker 内 gzip 压缩，Track 路径不承担 HTTP 处理。
     */
    public function testSendCommandGzipsBodyAboveConfiguredThreshold(): void
    {
        $queue = new MemoryEventQueue();
        $queue->enqueue(['{"event":"GzipEvent"}']);
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
            new Config(eventQueue: $queue, gzipThresholdBytes: 1),
            $transport
        );

        self::assertSame(0, $command->run());
        self::assertCount(1, $transport->requests);
        $request = $transport->requests[0];
        self::assertSame('gzip', $request->headers['Content-Encoding'] ?? null);
        self::assertSame('[{"event":"GzipEvent"}]', gzdecode($request->body));
    }
}
