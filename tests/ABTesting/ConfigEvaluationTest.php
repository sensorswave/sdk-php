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

    public function testConfigPublicDistributionMatchesRolloutChain(): void
    {
        $core = new ABCore(FixtureLoader::loadStorageFromJson(
            dirname(__DIR__) . '/Fixtures/ab/config/public.json'
        ));

        $totalUsers = 1000;
        $counts = [];
        for ($index = 0; $index < $totalUsers; $index++) {
            $result = $core->evaluate(
                new User('', 'config-public-user-' . $index),
                'bMHsfOAUKx',
                ABCore::TYPE_CONFIG
            );
            self::assertNotNull($result->variantId);
            $counts[$result->variantId] = ($counts[$result->variantId] ?? 0) + 1;
        }

        self::assertArrayHasKey('v1', $counts);
        self::assertArrayHasKey('v2', $counts);
        self::assertArrayHasKey('v3', $counts);
        self::assertEqualsWithDelta(0.10, $counts['v1'] / $totalUsers, 0.05);
        self::assertEqualsWithDelta(0.30, $counts['v2'] / $totalUsers, 0.05);
        self::assertEqualsWithDelta(0.60, $counts['v3'] / $totalUsers, 0.05);
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

    public function testConfigHoldoutRateStaysNearExpectedRange(): void
    {
        $core = new ABCore(FixtureLoader::loadStorageFromJson(
            dirname(__DIR__) . '/Fixtures/ab/config/holdout.json'
        ));

        $totalUsers = 1000;
        $holdoutCount = 0;
        $variantCount = [];
        for ($index = 0; $index < $totalUsers; $index++) {
            $result = $core->evaluate(
                new User('', 'config-holdout-user-' . $index),
                'bMHsfOAUKx',
                ABCore::TYPE_CONFIG
            );
            self::assertNotNull($result->variantId);
            if ($result->variantId === 'holdout') {
                $holdoutCount++;
                continue;
            }
            $variantCount[$result->variantId] = ($variantCount[$result->variantId] ?? 0) + 1;
        }

        self::assertEquals($totalUsers - $holdoutCount, array_sum($variantCount));
        self::assertEqualsWithDelta(0.10, $holdoutCount / $totalUsers, 0.03);
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

    public function testConfigTargetBlocksLowVersionAndAllowsQualifiedUsers(): void
    {
        $core = new ABCore(FixtureLoader::loadStorageFromJson(
            dirname(__DIR__) . '/Fixtures/ab/config/target.json'
        ));

        $blocked = $core->evaluate(
            new User('', 'blocked', Properties::create()->set('$app_version', '10.0')),
            'bMHsfOAUKx',
            ABCore::TYPE_CONFIG
        );
        self::assertNull($blocked->variantId);

        $allowed = null;
        foreach (range(0, 50) as $index) {
            $result = $core->evaluate(
                new User('', 'config-target-user-' . $index, Properties::create()->set('$app_version', '10.1')),
                'bMHsfOAUKx',
                ABCore::TYPE_CONFIG
            );
            if ($result->variantId !== null) {
                $allowed = $result;
                break;
            }
        }

        self::assertNotNull($allowed);
        self::assertContains($allowed->getString('color', ''), ['blue', 'red', 'orange']);
    }
}
