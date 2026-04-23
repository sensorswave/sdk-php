<?php

declare(strict_types=1);

namespace SensorsWave\Tests\Track;

use DateTimeImmutable;
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
        $messages = $queue->dequeue(50);
        self::assertNotSame([], $messages);
        $event = json_decode($messages[0]->payload, true);
        self::assertSame('$Identify', $event['event']);
        self::assertSame('anon-123', $event['anon_id']);
        self::assertSame('user-456', $event['login_id']);
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

        $messages = $queue->dequeue(50);
        self::assertCount(3, $messages);
        $e0 = json_decode($messages[0]->payload, true);
        $e1 = json_decode($messages[1]->payload, true);
        $e2 = json_decode($messages[2]->payload, true);
        self::assertSame('ArrayEvent', $e0['event']);
        self::assertSame('/pricing', $e0['properties']['page_name']);
        self::assertSame('pro', $e1['user_properties']['$set']['plan']);
        self::assertSame(['coins' => 3], $e2['user_properties']['$increment']);
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
            public function enqueue(array $payloads): void
            {
                throw new RuntimeException('queue down');
            }

            public function dequeue(int $limit): array
            {
                return [];
            }

            public function ack(array $messages): void
            {
            }

            public function nack(array $messages): void
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
        $messages = $queue->dequeue(50);
        self::assertCount(2, $messages);
        self::assertSame('BufferedOne', json_decode($messages[0]->payload, true)['event']);
        self::assertSame('BufferedTwo', json_decode($messages[1]->payload, true)['event']);
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

        $messages = $queue->dequeue(50);

        self::assertCount(2, $messages);
        self::assertSame('FlushOne', json_decode($messages[0]->payload, true)['event']);
        self::assertSame('FlushTwo', json_decode($messages[1]->payload, true)['event']);
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

        $messages = $queue->dequeue(50);
        self::assertCount(50, $messages);
        self::assertSame('BatchEvent1', json_decode($messages[0]->payload, true)['event']);
        self::assertSame('BatchEvent50', json_decode($messages[49]->payload, true)['event']);

        $client->close();
    }

    public function testCreateRejectsUnsupportedEndpointScheme(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('scheme must be http or https');

        Client::create('ftp://collector.example.com', 'test-token', new Config(eventQueue: new MemoryEventQueue()));
    }

    public function testProfileAppendAndUnionEncodeNativeDateTimeAsIso8601UtcStrings(): void
    {
        $queue = new MemoryEventQueue();
        $client = Client::create(
            'https://collector.example.com',
            'test-token',
            new Config(eventQueue: $queue)
        );

        $time = new DateTimeImmutable('2026-04-23T08:15:30.123Z');
        $user = new User('', 'user-456');

        $client->profileAppend($user, ['milestones' => [$time, $time]]);
        $client->profileUnion($user, ['milestones' => [$time, $time]]);
        $client->close();

        $messages = $queue->dequeue(50);
        self::assertCount(2, $messages);

        $appendEvent = json_decode($messages[0]->payload, true, 512, JSON_THROW_ON_ERROR);
        $unionEvent = json_decode($messages[1]->payload, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(
            ['2026-04-23T08:15:30.123Z', '2026-04-23T08:15:30.123Z'],
            $appendEvent['user_properties']['$append']['milestones']
        );
        self::assertSame(
            ['2026-04-23T08:15:30.123Z'],
            $unionEvent['user_properties']['$union']['milestones']
        );
    }
}
