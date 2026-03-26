<?php

declare(strict_types=1);

namespace SensorsWave\Storage;

use JsonException;
use RuntimeException;
use SensorsWave\Contract\EventQueueInterface;

/**
 * 基于本地文件的事件队列。
 */
final class LocalFileEventQueue implements EventQueueInterface
{
    private readonly string $queuePath;
    private readonly string $claimDirectory;
    private readonly string $lockPath;

    public function __construct(
        string $queuePath = '',
        string $claimDirectory = '',
    ) {
        $resolvedQueuePath = $queuePath !== ''
            ? $queuePath
            : sys_get_temp_dir() . '/sensorswave-event-queue.json';
        $resolvedClaimDirectory = $claimDirectory !== ''
            ? $claimDirectory
            : sys_get_temp_dir() . '/sensorswave-event-claims';

        $this->queuePath = $resolvedQueuePath;
        $this->claimDirectory = $resolvedClaimDirectory;
        $this->lockPath = $resolvedQueuePath . '.lock';
    }

    public function enqueue(array $events): void
    {
        $this->withLock(function () use ($events): void {
            $queue = $this->readQueue();
            $queue[] = [
                'batch_id' => uniqid('batch-', true),
                'events' => $events,
            ];
            $this->writeQueue($queue);
        });
    }

    public function dequeue(int $maxItems): ?EventBatch
    {
        return $this->withLock(function () use ($maxItems): ?EventBatch {
            $queue = $this->readQueue();
            if ($queue === []) {
                return null;
            }

            /** @var array{batch_id?: mixed, events?: mixed} $payload */
            $payload = array_shift($queue);
            $this->writeQueue($queue);

            $batchId = is_string($payload['batch_id'] ?? null)
                ? $payload['batch_id']
                : uniqid('batch-', true);
            /** @var list<array<string, mixed>> $events */
            $events = is_array($payload['events'] ?? null)
                ? array_slice($payload['events'], 0, max(1, $maxItems))
                : [];

            $this->writeClaim($batchId, $events);

            return new EventBatch($batchId, $events);
        });
    }

    public function ack(string $batchId): void
    {
        $this->withLock(function () use ($batchId): void {
            $claimPath = $this->claimPath($batchId);
            if (is_file($claimPath)) {
                @unlink($claimPath);
            }
        });
    }

    public function nack(string $batchId): void
    {
        $this->withLock(function () use ($batchId): void {
            $claimPath = $this->claimPath($batchId);
            if (!is_file($claimPath)) {
                return;
            }

            $contents = file_get_contents($claimPath);
            if ($contents === false || $contents === '') {
                @unlink($claimPath);
                return;
            }

            try {
                /** @var array{batch_id?: mixed, events?: mixed} $payload */
                $payload = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                @unlink($claimPath);
                return;
            }

            $queue = $this->readQueue();
            array_unshift($queue, $payload);
            $this->writeQueue($queue);
            @unlink($claimPath);
        });
    }

    /**
     * @return list<array{batch_id: string, events: list<array<string, mixed>>}>
     */
    private function readQueue(): array
    {
        if (!is_file($this->queuePath)) {
            return [];
        }

        $contents = file_get_contents($this->queuePath);
        if ($contents === false || $contents === '') {
            return [];
        }

        try {
            /** @var list<array{batch_id: string, events: list<array<string, mixed>>}> $decoded */
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
            return $decoded;
        } catch (JsonException) {
            return [];
        }
    }

    /**
     * @param list<array{batch_id: string, events: list<array<string, mixed>>}> $queue
     */
    private function writeQueue(array $queue): void
    {
        $directory = dirname($this->queuePath);
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException('failed to create event queue directory');
        }

        try {
            $json = json_encode($queue, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('failed to encode event queue payload', 0, $exception);
        }

        if (file_put_contents($this->queuePath, $json) === false) {
            throw new RuntimeException('failed to write event queue');
        }
    }

    /**
     * @param list<array<string, mixed>> $events
     */
    private function writeClaim(string $batchId, array $events): void
    {
        if (!is_dir($this->claimDirectory) && !mkdir($this->claimDirectory, 0777, true) && !is_dir($this->claimDirectory)) {
            throw new RuntimeException('failed to create event claim directory');
        }

        try {
            $json = json_encode([
                'batch_id' => $batchId,
                'events' => $events,
            ], JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('failed to encode event claim', 0, $exception);
        }

        if (file_put_contents($this->claimPath($batchId), $json) === false) {
            throw new RuntimeException('failed to write event claim');
        }
    }

    private function claimPath(string $batchId): string
    {
        return $this->claimDirectory . '/' . str_replace(['/', '\\'], '-', $batchId) . '.json';
    }

    /**
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    private function withLock(callable $callback): mixed
    {
        $directory = dirname($this->lockPath);
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException('failed to create event queue lock directory');
        }

        $handle = fopen($this->lockPath, 'c+');
        if ($handle === false) {
            throw new RuntimeException('failed to open event queue lock file');
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException('failed to lock event queue');
            }

            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
