<?php

declare(strict_types=1);

namespace SensorsWave\Tests\Storage;

use PHPUnit\Framework\TestCase;
use SensorsWave\Contract\LoggerInterface;
use SensorsWave\Storage\LocalFileEventQueue;

final class LocalFileEventQueueTest extends TestCase
{
    private string $queuePath;
    private string $claimDir;

    protected function setUp(): void
    {
        $suffix = uniqid('', true);
        $this->queuePath = sys_get_temp_dir() . '/sensorswave-event-queue-' . $suffix . '.json';
        $this->claimDir = sys_get_temp_dir() . '/sensorswave-event-claims-' . $suffix;
    }

    protected function tearDown(): void
    {
        @unlink($this->queuePath);
        if (is_dir($this->claimDir)) {
            $entries = scandir($this->claimDir);
            if (is_array($entries)) {
                foreach ($entries as $entry) {
                    if ($entry === '.' || $entry === '..') {
                        continue;
                    }
                    @unlink($this->claimDir . '/' . $entry);
                }
            }
            @rmdir($this->claimDir);
        }
    }

    public function testEnqueueDequeueAckLifecycle(): void
    {
        $queue = new LocalFileEventQueue($this->queuePath, $this->claimDir);

        $queue->enqueue(['{"event":"PageView"}', '{"event":"Purchase"}']);

        $messages = $queue->dequeue(50);
        self::assertCount(2, $messages);
        self::assertSame('{"event":"PageView"}', $messages[0]->payload);
        self::assertSame('{"event":"Purchase"}', $messages[1]->payload);

        $queue->ack($messages);
        self::assertSame([], $queue->dequeue(50));
    }

    public function testNackPutsMessagesBackIntoQueue(): void
    {
        $queue = new LocalFileEventQueue($this->queuePath, $this->claimDir);

        $queue->enqueue(['{"event":"RetryEvent"}']);

        $messages = $queue->dequeue(50);
        self::assertCount(1, $messages);

        $queue->nack($messages);

        $retried = $queue->dequeue(50);
        self::assertCount(1, $retried);
        self::assertSame('{"event":"RetryEvent"}', $retried[0]->payload);
    }

    public function testEnqueueUsesAppendFriendlyLineFormat(): void
    {
        $queue = new LocalFileEventQueue($this->queuePath, $this->claimDir);

        $queue->enqueue(['{"event":"First"}']);
        $queue->enqueue(['{"event":"Second"}']);

        $contents = (string) file_get_contents($this->queuePath);
        self::assertFalse(str_starts_with($contents, '['));
        self::assertSame(
            ['{"event":"First"}', '{"event":"Second"}'],
            array_map(
                fn(string $line): string => (string) json_decode($line, true, 512, JSON_THROW_ON_ERROR),
                array_values(array_filter(explode(PHP_EOL, trim($contents))))
            )
        );
    }

    public function testDequeueCanReadLegacyJsonArrayQueue(): void
    {
        file_put_contents($this->queuePath, '["{\"event\":\"Legacy\"}"]');
        $queue = new LocalFileEventQueue($this->queuePath, $this->claimDir);

        $messages = $queue->dequeue(50);

        self::assertCount(1, $messages);
        self::assertSame('{"event":"Legacy"}', $messages[0]->payload);
    }

    public function testDequeueSkipsCorruptLineAndLogsWarning(): void
    {
        file_put_contents(
            $this->queuePath,
            json_encode('{"event":"First"}', JSON_THROW_ON_ERROR) . PHP_EOL
                . '{broken' . PHP_EOL
                . json_encode('{"event":"Second"}', JSON_THROW_ON_ERROR) . PHP_EOL
        );
        $logger = new class implements LoggerInterface {
            /** @var list<string> */
            public array $warnings = [];

            public function debug(string $message, mixed ...$context): void
            {
            }

            public function info(string $message, mixed ...$context): void
            {
            }

            public function warn(string $message, mixed ...$context): void
            {
                $this->warnings[] = $message;
            }

            public function error(string $message, mixed ...$context): void
            {
            }
        };
        $queue = new LocalFileEventQueue($this->queuePath, $this->claimDir, $logger);

        $messages = $queue->dequeue(50);

        self::assertCount(2, $messages);
        self::assertSame('{"event":"First"}', $messages[0]->payload);
        self::assertSame('{"event":"Second"}', $messages[1]->payload);
        self::assertSame(['event queue line decode failed; skipping payload'], $logger->warnings);
    }
}
