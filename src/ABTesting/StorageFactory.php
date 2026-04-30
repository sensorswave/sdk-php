<?php

declare(strict_types=1);

namespace SensorsWave\ABTesting;

use JsonException;
use SensorsWave\ABTesting\Model\ABEnv;
use SensorsWave\ABTesting\Model\ABSpec;

/**
 * Factory for the A/B Storage snapshot.
 */
final class StorageFactory
{
    /**
     * Build a Storage from a decoded payload array.
     *
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): Storage
    {
        /** @var array<string, mixed> $data */
        $data = isset($payload['data']) && is_array($payload['data'])
            ? $payload['data']
            : $payload;

        $specs = [];
        $rawSpecs = $data['ab_specs'] ?? $data['ABSpecs'] ?? [];
        foreach ((array) $rawSpecs as $spec) {
            if (!is_array($spec)) {
                continue;
            }

            $abSpec = ABSpec::fromArray($spec);
            $specs[$abSpec->key] = $abSpec;
        }

        return new Storage(
            (int) ($data['updated_at'] ?? $data['update_time'] ?? $data['UpdateTime'] ?? 0),
            ABEnv::fromArray((array) ($data['ab_env'] ?? $data['ABEnv'] ?? [])),
            $specs,
        );
    }

    /**
     * Build a Storage from a JSON string.
     *
     * @throws JsonException
     */
    public static function fromJson(string $json): Storage
    {
        /** @var array<string, mixed> $payload */
        $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return self::fromArray($payload);
    }
}
