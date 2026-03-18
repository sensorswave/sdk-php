<?php

declare(strict_types=1);

namespace SensorsWave\Tests\ABTesting;

use PHPUnit\Framework\TestCase;
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
}
