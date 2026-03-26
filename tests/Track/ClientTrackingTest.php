<?php

declare(strict_types=1);

namespace SensorsWave\Tests\Track;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SensorsWave\Client\Client;
use SensorsWave\Config\Config;
use SensorsWave\Exception\EmptyUserIdsException;
use SensorsWave\Exception\IdentifyRequiresBothIdsException;
use SensorsWave\Model\Properties;
use SensorsWave\Model\User;
use SensorsWave\Tests\Support\FakeTransport;
use SensorsWave\Tests\Support\MemoryEventQueue;

final class ClientTrackingTest extends TestCase
{
    public function testIdentifyEnqueuesExpectedPayloadWithoutHttp(): void
    {
        $queue = new MemoryEventQueue();
        $transport = new FakeTransport();
        $client = Client::create(
            'https://collector.example.com/path',
            'test-token',
            new Config(transport: $transport, eventQueue: $queue)
        );

        $client->identify(new User('anon-123', 'user-456'));
        $client->close();

        self::assertCount(0, $transport->requests);
        $batch = $queue->dequeue(50);
        self::assertNotNull($batch);
        self::assertSame('$Identify', $batch->events[0]['event']);
        self::assertSame('anon-123', $batch->events[0]['anon_id']);
        self::assertSame('user-456', $batch->events[0]['login_id']);
    }

    public function testIdentifyRequiresBothIds(): void
    {
        $client = Client::create(
            'https://collector.example.com',
            'test-token',
            new Config(eventQueue: new MemoryEventQueue())
        );

        $this->expectException(IdentifyRequiresBothIdsException::class);
        $client->identify(new User('', 'user-456'));
    }

    public function testTrackEventAndProfileOperationsAcceptPlainPhpArrays(): void
    {
        $queue = new MemoryEventQueue();
        $client = Client::create(
            'https://collector.example.com',
            'test-token',
            new Config(eventQueue: $queue)
        );

        $user = new User('anon-123', 'user-456');

        $client->trackEvent($user, 'ArrayEvent', ['page_name' => '/pricing']);
        $client->profileSet($user, ['plan' => 'pro']);
        $client->profileIncrement($user, ['coins' => 3, 'plan' => 'pro']);
        $client->close();

        $batch = $queue->dequeue(50);
        self::assertNotNull($batch);
        self::assertCount(3, $batch->events);
        self::assertSame('ArrayEvent', $batch->events[0]['event']);
        self::assertSame('/pricing', $batch->events[0]['properties']['page_name']);
        self::assertSame('pro', $batch->events[1]['user_properties']['$set']['plan']);
        self::assertSame(['coins' => 3], $batch->events[2]['user_properties']['$increment']);
    }

    public function testTrackEventRequiresAtLeastOneUserId(): void
    {
        $client = Client::create(
            'https://collector.example.com',
            'test-token',
            new Config(eventQueue: new MemoryEventQueue())
        );

        $this->expectException(EmptyUserIdsException::class);
        $client->trackEvent(new User('', ''), 'PageView', Properties::create());
    }

    public function testTrackEventRejectsNewEventsAfterClose(): void
    {
        $client = Client::create(
            'https://collector.example.com',
            'test-token',
            new Config(eventQueue: new MemoryEventQueue())
        );
        $client->close();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the client was already closed');
        $client->trackEvent(new User('anon-123', 'user-456'), 'AfterClose', []);
    }

    public function testTrackEventInvokesFailureHandlerWhenQueueWriteFails(): void
    {
        $failedEvents = null;
        $failedError = null;
        $queue = new class implements \SensorsWave\Contract\EventQueueInterface {
            public function enqueue(array $events): void
            {
                throw new RuntimeException('queue down');
            }

            public function dequeue(int $maxItems): ?\SensorsWave\Storage\EventBatch
            {
                return null;
            }

            public function ack(string $batchId): void
            {
            }

            public function nack(string $batchId): void
            {
            }
        };

        $client = Client::create(
            'https://collector.example.com',
            'test-token',
            new Config(
                eventQueue: $queue,
                onTrackFailHandler: function (array $events, ?\Throwable $error) use (&$failedEvents, &$failedError): void {
                    $failedEvents = $events;
                    $failedError = $error;
                }
            )
        );

        $client->trackEvent(new User('anon-123', 'user-456'), 'RetryEvent', ['page' => '/retry']);
        $client->close();

        self::assertIsArray($failedEvents);
        self::assertSame('RetryEvent', $failedEvents[0]['event']);
        self::assertSame('queue down', $failedError?->getMessage());
    }

    public function testCloseFlushesBufferedEventsInSingleBatch(): void
    {
        $queue = new MemoryEventQueue();
        $transport = new FakeTransport();
        $client = Client::create(
            'https://collector.example.com',
            'test-token',
            new Config(transport: $transport, eventQueue: $queue)
        );

        $client->trackEvent(new User('anon-1', 'user-1'), 'BufferedOne', ['step' => 1]);
        $client->trackEvent(new User('anon-2', 'user-2'), 'BufferedTwo', ['step' => 2]);
        $client->close();

        self::assertCount(0, $transport->requests);
        $batch = $queue->dequeue(50);
        self::assertNotNull($batch);
        self::assertCount(2, $batch->events);
        self::assertSame('BufferedOne', $batch->events[0]['event']);
        self::assertSame('BufferedTwo', $batch->events[1]['event']);
    }

    public function testFlushWritesBufferedEventsWithoutClosingClient(): void
    {
        $queue = new MemoryEventQueue();
        $client = Client::create(
            'https://collector.example.com',
            'test-token',
            new Config(eventQueue: $queue)
        );

        $client->trackEvent(new User('anon-1', 'user-1'), 'FlushOne', ['step' => 1]);
        $client->flush();
        $client->trackEvent(new User('anon-2', 'user-2'), 'FlushTwo', ['step' => 2]);
        $client->close();

        $firstBatch = $queue->dequeue(50);
        $secondBatch = $queue->dequeue(50);

        self::assertNotNull($firstBatch);
        self::assertNotNull($secondBatch);
        self::assertSame('FlushOne', $firstBatch->events[0]['event']);
        self::assertSame('FlushTwo', $secondBatch->events[0]['event']);
    }

    public function testTrackFlushesWhenBatchReachesFiftyEvents(): void
    {
        $queue = new MemoryEventQueue();
        $client = Client::create(
            'https://collector.example.com',
            'test-token',
            new Config(eventQueue: $queue)
        );

        for ($index = 1; $index <= 50; $index++) {
            $client->trackEvent(
                new User('anon-' . $index, 'user-' . $index),
                'BatchEvent' . $index,
                ['index' => $index]
            );
        }

        $batch = $queue->dequeue(50);
        self::assertNotNull($batch);
        self::assertCount(50, $batch->events);
        self::assertSame('BatchEvent1', $batch->events[0]['event']);
        self::assertSame('BatchEvent50', $batch->events[49]['event']);

        $client->close();
    }

    public function testCreateRejectsUnsupportedEndpointScheme(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('scheme must be http or https');

        Client::create('ftp://collector.example.com', 'test-token', new Config(eventQueue: new MemoryEventQueue()));
    }
}
