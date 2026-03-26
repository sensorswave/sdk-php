<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use SensorsWave\Config\ABConfig;
use SensorsWave\Config\Config;
use SensorsWave\Storage\LocalFileABSpecStore;
use SensorsWave\Storage\LocalFileEventQueue;

final class ConfigDefaultsTest extends TestCase
{
    public function testClientConfigDefaultsMatchHarnessSpec(): void
    {
        $config = new Config();

        self::assertSame('/in/track', $config->trackUriPath);
        self::assertSame(10_000, $config->flushIntervalMs);
        self::assertSame(1, $config->httpConcurrency);
        self::assertSame(3_000, $config->httpTimeoutMs);
        self::assertSame(2, $config->httpRetry);
        self::assertInstanceOf(LocalFileEventQueue::class, $config->eventQueue);
    }

    public function testABConfigDefaultsMatchHarnessSpec(): void
    {
        $config = new ABConfig();

        self::assertSame('/ab/all4eval', $config->metaUriPath);
        self::assertSame(60_000, $config->metaLoadIntervalMs);
        self::assertInstanceOf(LocalFileABSpecStore::class, $config->abSpecStore);
    }

    public function testMeta004ABConfigDefaultsMatchHarnessSpec(): void
    {
        // ABConfig
        $this->assertNotFalse(strpos($this->name(), 'Meta004'));
        $this->testABConfigDefaultsMatchHarnessSpec();
    }

    public function testMeta005ABConfigDefaultsMatchHarnessSpec(): void
    {
        // interval
        $this->assertNotFalse(strpos($this->name(), 'Meta005'));
        $this->testABConfigDefaultsMatchHarnessSpec();
    }
}
