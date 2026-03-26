<?php

declare(strict_types=1);

namespace SensorsWave\Storage;

use JsonException;
use RuntimeException;
use SensorsWave\Contract\ABSpecStoreInterface;
use SensorsWave\Contract\RedisClientInterface;

/**
 * 基于 Redis 的 A/B snapshot 存储。
 */
final class RedisABSpecStore implements ABSpecStoreInterface
{
    public function __construct(
        private readonly RedisClientInterface $redis,
        private readonly string $key = 'sensorswave:ab_specs',
    ) {
    }

    public function load(): ?string
    {
        $payload = $this->redis->get($this->key);
        if (!is_string($payload) || $payload === '') {
            return null;
        }

        try {
            /** @var array{snapshot?: mixed} $decoded */
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_string($decoded['snapshot'] ?? null) ? $decoded['snapshot'] : null;
    }

    public function save(string $snapshot): void
    {
        try {
            $payload = json_encode([
                'snapshot' => $snapshot,
                'updated_at_ms' => (int) floor(microtime(true) * 1000),
            ], JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('failed to encode Redis AB snapshot payload', 0, $exception);
        }

        if (!$this->redis->set($this->key, $payload)) {
            throw new RuntimeException('failed to save Redis AB snapshot');
        }
    }

    public function metadata(): ABSpecStoreMetadata
    {
        $payload = $this->redis->get($this->key);
        if (!is_string($payload) || $payload === '') {
            return new ABSpecStoreMetadata(null);
        }

        try {
            /** @var array{updated_at_ms?: mixed} $decoded */
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return new ABSpecStoreMetadata(null);
        }

        return new ABSpecStoreMetadata(
            is_int($decoded['updated_at_ms'] ?? null) ? $decoded['updated_at_ms'] : null
        );
    }
}
