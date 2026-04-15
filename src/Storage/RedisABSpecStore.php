<?php

declare(strict_types=1);

namespace SensorsWave\Storage;

use JsonException;
use RuntimeException;
use SensorsWave\Contract\ABSpecStoreInterface;
use SensorsWave\Contract\RedisClientInterface;

final class RedisABSpecStore implements ABSpecStoreInterface
{
    public function __construct(
        private readonly RedisClientInterface $redis,
        private readonly string $key = '{sensorswave}:ab_specs',
    ) {
    }

    public function load(): ?string
    {
        $payload = $this->redis->get($this->key);
        if (!is_string($payload) || $payload === '') {
            return null;
        }

        return $payload;
    }

    public function save(string $snapshot): void
    {
        if (!$this->redis->set($this->key, $snapshot)) {
            throw new RuntimeException('failed to save Redis AB snapshot');
        }
    }

    public function metadata(): ABSpecStoreMetadata
    {
        $payload = $this->redis->get($this->key);
        if (!is_string($payload) || $payload === '') {
            return new ABSpecStoreMetadata(null);
        }

        return new ABSpecStoreMetadata(0);
    }
}
