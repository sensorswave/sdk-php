<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use SensorsWave\Config\ABConfig;
use SensorsWave\Config\Config;

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
    }

    public function testABConfigDefaultsMatchHarnessSpec(): void
    {
        $config = new ABConfig();

        self::assertSame('/ab/all4eval', $config->metaUriPath);
        self::assertSame(60_000, $config->metaLoadIntervalMs);
    }
}
