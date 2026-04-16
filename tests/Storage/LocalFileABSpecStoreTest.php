<?php

declare(strict_types=1);

namespace SensorsWave\Tests\Storage;

use PHPUnit\Framework\TestCase;
use SensorsWave\Storage\LocalFileABSpecStore;

final class LocalFileABSpecStoreTest extends TestCase
{
    private string $storePath;

    protected function setUp(): void
    {
        $this->storePath = sys_get_temp_dir() . '/sensorswave-ab-store-' . uniqid('', true) . '.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->storePath);
    }

    public function testStoreReturnsNullBeforeAnySnapshotIsSaved(): void
    {
        $store = new LocalFileABSpecStore($this->storePath);

        self::assertNull($store->load());
    }

    public function testStorePersistsSnapshot(): void
    {
        $store = new LocalFileABSpecStore($this->storePath);
        $snapshot = '{"code":0,"data":{"update":true,"update_time":1,"ab_specs":[]}}';

        $store->save($snapshot);

        self::assertSame($snapshot, $store->load());
    }
}
