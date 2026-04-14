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
    public function testConfigPublicFirstMatchWinsOnlyFirstRuleApplies(): void
    {
        $core = new ABCore(FixtureLoader::loadStorageFromJson(
            dirname(__DIR__) . '/Fixtures/ab/config/public.json'
        ));

        // First-match-wins: all users match rule 1 (IS_TRUE), only ~10% pass rollout → v1.
        // The remaining ~90% match but fail rollout → gate returns false → no variant.
        $totalUsers = 1000;
        $v1Count = 0;
        $nilCount = 0;

        for ($index = 0; $index < $totalUsers; $index++) {
            $result = $core->evaluate(
                new User('', 'config-public-user-' . $index),
                'bMHsfOAUKx',
                ABCore::TYPE_CONFIG
            );

            if ($result->variantId !== null) {
                self::assertSame('v1', $result->variantId);
                self::assertSame('blue', $result->getString('color', ''));
                $v1Count++;
            } else {
                $nilCount++;
            }
        }

        self::assertEqualsWithDelta(0.10, $v1Count / $totalUsers, 0.05);
        self::assertSame($totalUsers, $v1Count + $nilCount);
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

        // Traffic rule: rollout 90 → ~10% holdout.
        // First-match-wins gate: all non-holdout users match rule 1 (IS_TRUE), only ~10% pass rollout → v1.
        $totalUsers = 1000;
        $holdoutCount = 0;
        $v1Count = 0;
        $nilCount = 0;

        for ($index = 0; $index < $totalUsers; $index++) {
            $result = $core->evaluate(
                new User('', 'config-holdout-user-' . $index),
                'bMHsfOAUKx',
                ABCore::TYPE_CONFIG
            );

            if ($result->variantId === null) {
                $nilCount++;
            } elseif ($result->variantId === 'holdout') {
                $holdoutCount++;
            } else {
                self::assertSame('v1', $result->variantId);
                $v1Count++;
            }
        }

        self::assertEqualsWithDelta(0.10, $holdoutCount / $totalUsers, 0.03);
        $nonHoldout = $totalUsers - $holdoutCount;
        self::assertEqualsWithDelta(0.10, $v1Count / $nonHoldout, 0.05);
        self::assertSame($totalUsers, $holdoutCount + $v1Count + $nilCount);
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

    public function testConfigFirstMatchWinsVipGetsV1(): void
    {
        $core = new ABCore(FixtureLoader::loadStorageFromJson(
            dirname(__DIR__) . '/Fixtures/ab/config/first_match_wins.json'
        ));

        $result = $core->evaluate(new User('', 'vip-user-1'), 'config_first_match', ABCore::TYPE_CONFIG);

        self::assertSame('v1', $result->variantId);
        self::assertSame('vip', $result->getString('tier', ''));
    }

    public function testConfigFirstMatchWinsVipAlsoMemberStillGetsV1(): void
    {
        $core = new ABCore(FixtureLoader::loadStorageFromJson(
            dirname(__DIR__) . '/Fixtures/ab/config/first_match_wins.json'
        ));

        // VIP user who is also a member → first rule matches → v1 (not v2)
        $result = $core->evaluate(
            new User('', 'vip-user-2', Properties::create()->set('is_member', true)),
            'config_first_match',
            ABCore::TYPE_CONFIG
        );

        self::assertSame('v1', $result->variantId);
        self::assertSame('vip', $result->getString('tier', ''));
    }

    public function testConfigFirstMatchWinsMemberGetsV2(): void
    {
        $core = new ABCore(FixtureLoader::loadStorageFromJson(
            dirname(__DIR__) . '/Fixtures/ab/config/first_match_wins.json'
        ));

        $result = $core->evaluate(
            new User('', 'regular-member', Properties::create()->set('is_member', true)),
            'config_first_match',
            ABCore::TYPE_CONFIG
        );

        self::assertSame('v2', $result->variantId);
        self::assertSame('member', $result->getString('tier', ''));
    }

    public function testConfigFirstMatchWinsPublicUserGetsV3(): void
    {
        $core = new ABCore(FixtureLoader::loadStorageFromJson(
            dirname(__DIR__) . '/Fixtures/ab/config/first_match_wins.json'
        ));

        $result = $core->evaluate(new User('', 'anonymous-user'), 'config_first_match', ABCore::TYPE_CONFIG);

        self::assertSame('v3', $result->variantId);
        self::assertSame('public', $result->getString('tier', ''));
    }

    public function testConfigFirstMatchWinsNonMemberFallsToPublic(): void
    {
        $core = new ABCore(FixtureLoader::loadStorageFromJson(
            dirname(__DIR__) . '/Fixtures/ab/config/first_match_wins.json'
        ));

        // is_member=false → second rule doesn't match → fallback to public rule
        $result = $core->evaluate(
            new User('', 'plain-user', Properties::create()->set('is_member', false)),
            'config_first_match',
            ABCore::TYPE_CONFIG
        );

        self::assertSame('v3', $result->variantId);
        self::assertSame('public', $result->getString('tier', ''));
    }
}
