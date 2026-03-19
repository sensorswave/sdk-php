<?php

declare(strict_types=1);

namespace SensorsWave\ABTesting;

use JsonException;
use SensorsWave\ABTesting\Model\ABEnv;
use SensorsWave\ABTesting\Model\ABSpec;

/**
 * A/B storage 构造器。
 */
final class StorageFactory
{
    /**
     * 从 JSON 字符串创建 storage。
     *
     * @throws JsonException
     */
    public static function fromJson(string $json): Storage
    {
        /** @var array<string, mixed> $payload */
        $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
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
}
