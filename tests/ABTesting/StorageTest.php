<?php

declare(strict_types=1);

namespace SensorsWave\Tests\ABTesting;

use JsonException;
use PHPUnit\Framework\TestCase;
use SensorsWave\ABTesting\StorageFactory;
use SensorsWave\Tests\Support\FixtureLoader;

final class StorageTest extends TestCase
{
    public function testFixtureLoaderParsesStorageSnapshot(): void
    {
        $storage = FixtureLoader::loadStorageFromJson(
            dirname(__DIR__) . '/Fixtures/ab/config/public.json'
        );

        self::assertSame(1764658761824, $storage->updateTime);
        self::assertTrue($storage->hasSpec('bMHsfOAUKx'));

        $spec = $storage->getSpec('bMHsfOAUKx');
        self::assertNotNull($spec);
        self::assertSame(21, $spec->id);
        self::assertSame(2, $spec->type);
        self::assertSame('blue', $spec->variantValues['v1']['color']);
        self::assertSame('red', $spec->variantValues['v2']['color']);
        self::assertSame('orange', $spec->variantValues['v3']['color']);
    }

    /**
     * @throws JsonException
     */
    public function testStorageFactorySupportsGoStyleSnapshotPayload(): void
    {
        $snapshot = json_encode([
            'UpdateTime' => 123,
            'ABEnv' => [
                'always_track' => true,
            ],
            'ABSpecs' => [
                'snapshot_ff' => [
                    'id' => 9,
                    'key' => 'snapshot_ff',
                    'name' => 'Snapshot FF',
                    'typ' => 3,
                    'subject_id' => 'LOGIN_ID',
                    'enabled' => true,
                    'sticky' => false,
                    'rules' => [],
                    'variant_payloads' => [
                        '1' => ['color' => 'blue'],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $storage = StorageFactory::fromJson($snapshot);

        self::assertSame(123, $storage->updateTime);
        self::assertTrue($storage->abEnv->alwaysTrack);
        $spec = $storage->getSpec('snapshot_ff');
        self::assertNotNull($spec);
        self::assertSame('Snapshot FF', $spec->name);
        self::assertSame('blue', $spec->variantValues[1]['color'] ?? null);
    }
}
