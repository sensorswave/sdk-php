<?php

declare(strict_types=1);

namespace SensorsWave\Storage;

use RuntimeException;
use SensorsWave\Contract\ABSpecStoreInterface;

/**
 * 基于本地文件的 A/B snapshot 存储。
 */
final class LocalFileABSpecStore implements ABSpecStoreInterface
{
    private readonly string $path;
    private readonly string $lockPath;

    public function __construct(
        string $path = '',
    ) {
        $resolved = $path !== ''
            ? $path
            : sys_get_temp_dir() . '/sensorswave-ab-specs.json';
        $this->path = $resolved;
        $this->lockPath = $resolved . '.lock';
    }

    public function load(): ?string
    {
        return $this->withLock(function (): ?string {
            if (!is_file($this->path)) {
                return null;
            }

            $contents = file_get_contents($this->path);
            if ($contents === false || $contents === '') {
                return null;
            }

            return $contents;
        });
    }

    public function save(string $snapshot): void
    {
        $this->withLock(function () use ($snapshot): void {
            $directory = dirname($this->path);
            if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
                throw new RuntimeException('failed to create AB spec store directory');
            }

            $tempPath = $this->path . '.' . uniqid('tmp', true);
            if (file_put_contents($tempPath, $snapshot) === false) {
                throw new RuntimeException('failed to write AB spec snapshot');
            }

            if (!rename($tempPath, $this->path)) {
                @unlink($tempPath);
                throw new RuntimeException('failed to move AB spec snapshot into place');
            }
        });
    }

    public function metadata(): ABSpecStoreMetadata
    {
        return $this->withLock(function (): ABSpecStoreMetadata {
            if (!is_file($this->path)) {
                return new ABSpecStoreMetadata(null);
            }

            $mtime = filemtime($this->path);
            return new ABSpecStoreMetadata($mtime === false ? null : $mtime * 1000);
        });
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
            throw new RuntimeException('failed to create AB spec lock directory');
        }

        $handle = fopen($this->lockPath, 'c+');
        if ($handle === false) {
            throw new RuntimeException('failed to open AB spec lock file');
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException('failed to lock AB spec store');
            }

            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
