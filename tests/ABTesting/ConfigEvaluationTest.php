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
    public function testConfigPublicAssignsVariantsWithinMatchedRule(): void
    {
        $core = new ABCore(FixtureLoader::loadStorageFromJson(
            dirname(__DIR__) . '/testdata/config/public.json'
        ));

        $counts = self::sampleConfigVariants($core, 'config-public-user');
        self::assertConfigVariantSplit($counts['variants']);
    }

    public function testConfigOverrideHonorsExplicitUserRule(): void
    {
        $core = new ABCore(FixtureLoader::loadStorageFromJson(
            dirname(__DIR__) . '/testdata/config/override.json'
        ));

        $result = $core->evaluate(new User('', 'login-id-example-1'), 'bMHsfOAUKx', ABCore::TYPE_CONFIG);

        self::assertSame('v1', $result->variantId);
        self::assertSame('blue', $result->getString('color', ''));
    }

    public function testConfigHoldoutCanReturnHoldoutVariant(): void
    {
        $core = new ABCore(FixtureLoader::loadStorageFromJson(
            dirname(__DIR__) . '/testdata/config/holdout.json'
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
            dirname(__DIR__) . '/testdata/config/holdout.json'
        ));

        $counts = self::sampleConfigVariants($core, 'config-holdout-user');

        self::assertEqualsWithDelta(0.10, $counts['holdout'] / $counts['total'], 0.03);
        self::assertSame($counts['total'], $counts['holdout'] + $counts['variants']['total']);
        self::assertConfigVariantSplit($counts['variants']);
    }

    /**
     * @return array{total: int, holdout: int, variants: array{total: int, v1: int, v2: int, v3: int}}
     */
    private static function sampleConfigVariants(ABCore $core, string $userPrefix): array
    {
        $counts = [
            'total' => 1000,
            'holdout' => 0,
            'variants' => ['total' => 0, 'v1' => 0, 'v2' => 0, 'v3' => 0],
        ];

        for ($index = 0; $index < $counts['total']; $index++) {
            $result = $core->evaluate(
                new User('', $userPrefix . '-' . $index),
                'bMHsfOAUKx',
                ABCore::TYPE_CONFIG
            );

            switch ($result->variantId) {
                case 'holdout':
                    $counts['holdout']++;
                    break;
                case 'v1':
                    self::assertSame('blue', $result->getString('color', ''));
                    $counts['variants']['v1']++;
                    $counts['variants']['total']++;
                    break;
                case 'v2':
                    self::assertSame('red', $result->getString('color', ''));
                    $counts['variants']['v2']++;
                    $counts['variants']['total']++;
                    break;
                case 'v3':
                    self::assertSame('orange', $result->getString('color', ''));
                    $counts['variants']['v3']++;
                    $counts['variants']['total']++;
                    break;
                default:
                    self::fail('Expected holdout or a config variant, got ' . var_export($result->variantId, true));
            }
        }

        return $counts;
    }

    /**
     * @param array{total: int, v1: int, v2: int, v3: int} $counts
     */
    private static function assertConfigVariantSplit(array $counts): void
    {
        self::assertSame($counts['total'], $counts['v1'] + $counts['v2'] + $counts['v3']);
        self::assertEqualsWithDelta(0.10, $counts['v1'] / $counts['total'], 0.05);
        self::assertEqualsWithDelta(0.30, $counts['v2'] / $counts['total'], 0.05);
        self::assertEqualsWithDelta(0.60, $counts['v3'] / $counts['total'], 0.05);
    }

    public function testConfigStickyUsesCacheAndPersistsResult(): void
    {
        $handler = new MemoryStickyHandler();
        $handler->data['27-sticky-config-cache'] = json_encode(['v' => 'v1'], JSON_THROW_ON_ERROR);

        $core = new ABCore(
            FixtureLoader::loadStorageFromJson(dirname(__DIR__) . '/testdata/config/sticky.json'),
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
            dirname(__DIR__) . '/testdata/config/target.json'
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
            dirname(__DIR__) . '/testdata/config/first_match_wins.json'
        ));

        $result = $core->evaluate(new User('', 'vip-user-1'), 'config_first_match', ABCore::TYPE_CONFIG);

        self::assertSame('v1', $result->variantId);
        self::assertSame('vip', $result->getString('tier', ''));
    }

    public function testConfigFirstMatchWinsVipAlsoMemberStillGetsV1(): void
    {
        $core = new ABCore(FixtureLoader::loadStorageFromJson(
            dirname(__DIR__) . '/testdata/config/first_match_wins.json'
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
            dirname(__DIR__) . '/testdata/config/first_match_wins.json'
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
            dirname(__DIR__) . '/testdata/config/first_match_wins.json'
        ));

        $result = $core->evaluate(new User('', 'anonymous-user'), 'config_first_match', ABCore::TYPE_CONFIG);

        self::assertSame('v3', $result->variantId);
        self::assertSame('public', $result->getString('tier', ''));
    }

    public function testConfigFirstMatchWinsNonMemberFallsToPublic(): void
    {
        $core = new ABCore(FixtureLoader::loadStorageFromJson(
            dirname(__DIR__) . '/testdata/config/first_match_wins.json'
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
