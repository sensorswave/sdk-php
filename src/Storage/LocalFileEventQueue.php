<?php

declare(strict_types=1);

namespace SensorsWave\Storage;

use JsonException;
use RuntimeException;
use SensorsWave\Contract\EventQueueInterface;
use SensorsWave\Contract\LoggerInterface;
use SensorsWave\Support\DefaultLogger;

/**
 * Local-file backed event queue.
 */
final class LocalFileEventQueue implements EventQueueInterface
{
    private readonly string $queuePath;
    private readonly string $claimDirectory;
    private readonly string $lockPath;
    private readonly LoggerInterface $logger;

    public function __construct(
        string $queuePath = '',
        string $claimDirectory = '',
        ?LoggerInterface $logger = null,
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
        $this->logger = $logger ?? new DefaultLogger();
    }

    public function enqueue(array $payloads): void
    {
        $this->withLock(function () use ($payloads): void {
            $this->appendQueue($payloads);
        });
    }

    public function dequeue(int $limit): array
    {
        return $this->withLock(function () use ($limit): array {
            $queue = $this->readQueue();
            if ($queue === []) {
                return [];
            }

            $taken = array_splice($queue, 0, max(1, $limit));
            $this->writeQueue($queue);

            $receipt = uniqid('rcpt-', true);
            $this->writeClaim($receipt, $taken);

            $messages = [];
            foreach ($taken as $payload) {
                $messages[] = new QueueMessage($receipt, $payload);
            }

            return $messages;
        });
    }

    public function ack(array $messages): void
    {
        $this->withLock(function () use ($messages): void {
            $receipts = array_unique(array_map(
                fn(QueueMessage $m) => $m->receipt,
                $messages,
            ));
            foreach ($receipts as $receipt) {
                $claimPath = $this->claimPath($receipt);
                if (is_file($claimPath)) {
                    @unlink($claimPath);
                }
            }
        });
    }

    public function nack(array $messages): void
    {
        $this->withLock(function () use ($messages): void {
            $receipts = array_unique(array_map(
                fn(QueueMessage $m) => $m->receipt,
                $messages,
            ));

            $queue = $this->readQueue();
            foreach ($receipts as $receipt) {
                $claimPath = $this->claimPath($receipt);
                if (!is_file($claimPath)) {
                    continue;
                }

                $contents = file_get_contents($claimPath);
                if ($contents === false || $contents === '') {
                    @unlink($claimPath);
                    continue;
                }

                try {
                    /** @var list<string> $payloads */
                    $payloads = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
                } catch (JsonException) {
                    @unlink($claimPath);
                    continue;
                }

                array_unshift($queue, ...$payloads);
                @unlink($claimPath);
            }
            $this->writeQueue($queue);
        });
    }

    /** @return list<string> */
    private function readQueue(): array
    {
        if (!is_file($this->queuePath)) {
            return [];
        }

        $contents = file_get_contents($this->queuePath);
        if ($contents === false || $contents === '') {
            return [];
        }

        if (str_starts_with(ltrim($contents), '[')) {
            return $this->decodeLegacyJsonQueue($contents);
        }

        return $this->decodeLineQueue($contents);
    }

    /**
     * @return list<string>
     */
    private function decodeLegacyJsonQueue(string $contents): array
    {
        try {
            /** @var list<string> $decoded */
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
            return $decoded;
        } catch (JsonException) {
            return [];
        }
    }

    /**
     * @return list<string>
     */
    private function decodeLineQueue(string $contents): array
    {
        $queue = [];
        foreach (preg_split('/\R/', $contents) ?: [] as $index => $line) {
            if ($line === '') {
                continue;
            }
            try {
                $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                $this->logger->warn(
                    'event queue line decode failed; skipping payload',
                    ['line' => $index + 1, 'error' => $exception->getMessage()]
                );
                continue;
            }
            if (is_string($decoded)) {
                $queue[] = $decoded;
                continue;
            }
            $this->logger->warn(
                'event queue line has invalid payload type; skipping payload',
                ['line' => $index + 1]
            );
        }

        return $queue;
    }

    /** @param list<string> $payloads */
    private function appendQueue(array $payloads): void
    {
        if ($payloads === []) {
            return;
        }

        $directory = dirname($this->queuePath);
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException('failed to create event queue directory');
        }

        $lines = $this->encodeLineQueue($payloads);
        if (file_put_contents($this->queuePath, $lines, FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException('failed to append event queue');
        }
    }

    /** @param list<string> $queue */
    private function writeQueue(array $queue): void
    {
        $directory = dirname($this->queuePath);
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException('failed to create event queue directory');
        }

        $tempPath = $this->queuePath . '.' . uniqid('tmp', true);
        if (file_put_contents($tempPath, $this->encodeLineQueue($queue)) === false) {
            throw new RuntimeException('failed to write event queue');
        }

        if (!rename($tempPath, $this->queuePath)) {
            @unlink($tempPath);
            throw new RuntimeException('failed to move event queue into place');
        }
    }

    /**
     * @param list<string> $payloads
     */
    private function encodeLineQueue(array $payloads): string
    {
        $lines = '';
        foreach ($payloads as $payload) {
            try {
                $lines .= json_encode($payload, JSON_THROW_ON_ERROR) . PHP_EOL;
            } catch (JsonException $exception) {
                throw new RuntimeException('failed to encode event queue payload', 0, $exception);
            }
        }

        return $lines;
    }

    /** @param list<string> $payloads */
    private function writeClaim(string $receipt, array $payloads): void
    {
        if (!is_dir($this->claimDirectory) && !mkdir($this->claimDirectory, 0777, true) && !is_dir($this->claimDirectory)) {
            throw new RuntimeException('failed to create event claim directory');
        }

        try {
            $json = json_encode($payloads, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('failed to encode event claim', 0, $exception);
        }

        $claimPath = $this->claimPath($receipt);
        $tempPath = $claimPath . '.' . uniqid('tmp', true);
        if (file_put_contents($tempPath, $json) === false) {
            throw new RuntimeException('failed to write event claim');
        }

        if (!rename($tempPath, $claimPath)) {
            @unlink($tempPath);
            throw new RuntimeException('failed to move event claim into place');
        }
    }

    private function claimPath(string $receipt): string
    {
        return $this->claimDirectory . '/' . str_replace(['/', '\\'], '-', $receipt) . '.json';
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
