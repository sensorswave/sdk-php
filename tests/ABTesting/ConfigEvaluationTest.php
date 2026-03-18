<?php

declare(strict_types=1);

namespace SensorsWave\Tests\ABTesting;

use PHPUnit\Framework\TestCase;
use SensorsWave\ABTesting\ABCore;
use SensorsWave\Model\User;
use SensorsWave\Tests\Support\FixtureLoader;

final class ConfigEvaluationTest extends TestCase
{
    public function testConfigPublicProducesVariantPayload(): void
    {
        $core = new ABCore(FixtureLoader::loadStorageFromJson(
            dirname(__DIR__) . '/Fixtures/ab/config/public.json'
        ));

        $result = $core->evaluate(new User('', 'config-public-user-1'), 'bMHsfOAUKx', ABCore::TYPE_CONFIG);

        self::assertNotNull($result->variantId);
        self::assertContains($result->variantId, ['v1', 'v2', 'v3']);
        self::assertContains($result->getString('color', ''), ['blue', 'red', 'orange']);
    }

    public function testConfigOverrideHonorsExplicitUserRule(): void
    {
        $core = new ABCore(FixtureLoader::loadStorageFromJson(
            dirname(__DIR__) . '/Fixtures/ab/config/override.json'
        ));

        $result = $core->evaluate(new User('', 'login-id-example-1'), 'bMHsfOAUKx', ABCore::TYPE_CONFIG);

        self::assertSame('v1', $result->variantId);
        self::assertSame('blue', $result->getString('color', ''));
    }
}
