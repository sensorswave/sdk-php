<?php

declare(strict_types=1);

namespace SensorsWave\Tests\Support;

use JsonException;
use SensorsWave\ABTesting\Storage;
use SensorsWave\ABTesting\StorageFactory;

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

        return StorageFactory::fromJson($contents);
    }
}
