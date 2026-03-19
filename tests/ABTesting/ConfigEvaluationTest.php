<?php

declare(strict_types=1);

namespace SensorsWave\Tests\ABTesting;

use PHPUnit\Framework\TestCase;
use SensorsWave\ABTesting\ABCore;
use SensorsWave\Model\User;
use SensorsWave\Model\Properties;
use SensorsWave\Tests\Support\FixtureLoader;
use SensorsWave\Tests\Support\MemoryStickyHandler;

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

    public function testConfigHoldoutCanReturnHoldoutVariant(): void
    {
        $core = new ABCore(FixtureLoader::loadStorageFromJson(
            dirname(__DIR__) . '/Fixtures/ab/config/holdout.json'
        ));

        $holdoutUser = null;
        foreach (range(0, 200) as $index) {
            $loginId = 'config-holdout-user-' . $index;
            $result = $core->evaluate(new User('', $loginId), 'bMHsfOAUKx', ABCore::TYPE_CONFIG);
            if ($result->variantId === 'holdout') {
                $holdoutUser = $loginId;
                break;
            }
        }

        self::assertNotNull($holdoutUser);
    }

    public function testConfigStickyUsesCacheAndPersistsResult(): void
    {
        $handler = new MemoryStickyHandler();
        $handler->data['27-sticky-config-cache'] = json_encode(['v' => 'v1'], JSON_THROW_ON_ERROR);

        $core = new ABCore(
            FixtureLoader::loadStorageFromJson(dirname(__DIR__) . '/Fixtures/ab/config/sticky.json'),
            $handler
        );

        $cached = $core->evaluate(
            new User('', 'sticky-config-cache', Properties::create()->set('is_member', false)),
            'Sticky_Config',
            ABCore::TYPE_CONFIG
        );
        self::assertSame('v1', $cached->variantId);
        self::assertSame('blue', $cached->getString('color', ''));

        $fresh = $core->evaluate(
            new User('', 'sticky-config-new', Properties::create()->set('is_member', true)),
            'Sticky_Config',
            ABCore::TYPE_CONFIG
        );

        self::assertNotNull($fresh->variantId);
        self::assertArrayHasKey('27-sticky-config-new', $handler->data);
    }
}
