<?php

declare(strict_types=1);

namespace SensorsWave\Tests\Support;

use JsonException;
use SensorsWave\ABTesting\Model\ABEnv;
use SensorsWave\ABTesting\Model\ABSpec;
use SensorsWave\ABTesting\Storage;

/**
 * 测试夹具加载器。
 */
final class FixtureLoader
{
    /**
     * 从 JSON 文件读取 storage 快照。
     *
     * @throws JsonException
     */
    public static function loadStorageFromJson(string $path): Storage
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new \RuntimeException('failed to read fixture: ' . $path);
        }

        /** @var array<string, mixed> $payload */
        $payload = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        /** @var array<string, mixed> $data */
        $data = (array) ($payload['data'] ?? []);

        $specs = [];
        foreach (($data['ab_specs'] ?? []) as $spec) {
            if (!is_array($spec)) {
                continue;
            }

            $abSpec = ABSpec::fromArray($spec);
            $specs[$abSpec->key] = $abSpec;
        }

        return new Storage(
            (int) ($data['updated_at'] ?? $data['update_time'] ?? 0),
            ABEnv::fromArray((array) ($data['ab_env'] ?? [])),
            $specs,
        );
    }
}
