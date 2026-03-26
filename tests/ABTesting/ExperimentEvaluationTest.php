<?php

declare(strict_types=1);

namespace SensorsWave\Tests\ABTesting;

use PHPUnit\Framework\TestCase;
use SensorsWave\ABTesting\ABCore;
use SensorsWave\Model\User;
use SensorsWave\Model\Properties;
use SensorsWave\Tests\Support\FixtureLoader;
use SensorsWave\Tests\Support\MemoryStickyHandler;

final class ExperimentEvaluationTest extends TestCase
{
    public function testExperimentPublicAssignsVariantAndPayload(): void
    {
        $core = new ABCore(FixtureLoader::loadStorageFromJson(
            dirname(__DIR__) . '/Fixtures/ab/exp/public.json'
        ));

        $variantOneUser = null;
        $variantTwoUser = null;
        foreach (['user0', 'user1', 'user2', 'user3', 'alice', 'bob', 'charlie', 'david', 'eve'] as $loginId) {
            $result = $core->evaluate(new User('', $loginId), 'New_Experiment', ABCore::TYPE_EXPERIMENT);
            if ($result->variantId === 'v1' && $variantOneUser === null) {
                $variantOneUser = $loginId;
            }
            if ($result->variantId === 'v2' && $variantTwoUser === null) {
                $variantTwoUser = $loginId;
            }
            if ($variantOneUser !== null && $variantTwoUser !== null) {
                break;
            }
        }

        self::assertNotNull($variantOneUser);
        self::assertNotNull($variantTwoUser);

        $variantOne = $core->evaluate(new User('', $variantOneUser), 'New_Experiment', ABCore::TYPE_EXPERIMENT);
        $variantTwo = $core->evaluate(new User('', $variantTwoUser), 'New_Experiment', ABCore::TYPE_EXPERIMENT);

        self::assertSame('v1', $variantOne->variantId);
        self::assertSame(0.0, $variantOne->getNumber('test', -1));
        self::assertSame('str0', $variantOne->getString('test_str', ''));

        self::assertSame('v2', $variantTwo->variantId);
        self::assertSame(1.0, $variantTwo->getNumber('test', -1));
        self::assertSame('str1', $variantTwo->getString('test_str', ''));
    }

    public function testExperimentStickyUsesCacheAndPersistsResult(): void
    {
        $handler = new MemoryStickyHandler();
        $handler->data['26-sticky-user-cache'] = json_encode(['v' => 'v2'], JSON_THROW_ON_ERROR);

        $core = new ABCore(
            FixtureLoader::loadStorageFromJson(dirname(__DIR__) . '/Fixtures/ab/exp/sticky.json'),
            $handler
        );

        $cached = $core->evaluate(
            new User('', 'sticky-user-cache', Properties::create()->set('is_member', false)),
            'Sticky_Experiment',
            ABCore::TYPE_EXPERIMENT
        );
        self::assertSame('v2', $cached->variantId);
        self::assertSame('red', $cached->getString('color', ''));

        $fresh = $core->evaluate(
            new User('', 'sticky-user-new', Properties::create()->set('is_member', true)),
            'Sticky_Experiment',
            ABCore::TYPE_EXPERIMENT
        );
        self::assertNotNull($fresh->variantId);
        self::assertArrayHasKey('26-sticky-user-new', $handler->data);
    }

    public function testExperimentHoldoutCanReturnHoldoutVariant(): void
    {
        $core = new ABCore(FixtureLoader::loadStorageFromJson(
            dirname(__DIR__) . '/Fixtures/ab/exp/holdout.json'
        ));

        $holdoutUser = null;
        foreach (range(0, 200) as $index) {
            $loginId = 'holdout-user-' . $index;
            $result = $core->evaluate(new User('', $loginId), 'BKduZnxYPD', ABCore::TYPE_EXPERIMENT);
            if ($result->variantId === 'holdout') {
                $holdoutUser = $loginId;
                break;
            }
        }

        self::assertNotNull($holdoutUser);
    }

    public function testExperimentGateDependencyPassAndFail(): void
    {
        $passCore = new ABCore(FixtureLoader::loadStorageFromJson(
            dirname(__DIR__) . '/Fixtures/ab/exp/gate_target_pass.json'
        ));
        $failCore = new ABCore(FixtureLoader::loadStorageFromJson(
            dirname(__DIR__) . '/Fixtures/ab/exp/gate_target_fail.json'
        ));

        $blocked = $passCore->evaluate(
            new User('', 'user0', Properties::create()->set('$app_version', '9.9')),
            'ArlrvEnebz',
            ABCore::TYPE_EXPERIMENT
        );
        self::assertNull($blocked->variantId);

        $allowed = null;
        foreach (range(0, 200) as $index) {
            $loginId = 'user-pass-' . $index;
            $result = $passCore->evaluate(
                new User('', $loginId, Properties::create()->set('$app_version', '10.1')),
                'ArlrvEnebz',
                ABCore::TYPE_EXPERIMENT
            );
            if ($result->variantId !== null) {
                $allowed = $result;
                break;
            }
        }
        self::assertNotNull($allowed);

        $passWhenDependencyFails = $failCore->evaluate(
            new User('', 'user0', Properties::create()->set('$app_version', '9.9')),
            'Failed_Gate',
            ABCore::TYPE_EXPERIMENT
        );
        self::assertNotNull($passWhenDependencyFails->variantId);
    }

    public function testExperimentLayerAndLayerHoldout(): void
    {
        $layerCore = new ABCore(FixtureLoader::loadStorageFromJson(
            dirname(__DIR__) . '/Fixtures/ab/exp/layer.json'
        ));
        $layerHoldoutCore = new ABCore(FixtureLoader::loadStorageFromJson(
            dirname(__DIR__) . '/Fixtures/ab/exp/layer_with_holdout.json'
        ));

        $variantOne = null;
        $variantTwo = null;
        foreach (['user0', 'user1', 'user2', 'user3', 'alice', 'bob', 'charlie', 'david', 'eve', 'frank'] as $loginId) {
            $result = $layerCore->evaluate(new User('', $loginId), 'exp3', ABCore::TYPE_EXPERIMENT);
            if ($result->variantId === 'v1' && $variantOne === null) {
                $variantOne = $result;
            }
            if ($result->variantId === 'v2' && $variantTwo === null) {
                $variantTwo = $result;
            }
            if ($variantOne !== null && $variantTwo !== null) {
                break;
            }
        }

        self::assertNotNull($variantOne);
        self::assertNotNull($variantTwo);
        self::assertSame(1.0, $variantOne->getNumber('test', -1));
        self::assertSame(2.0, $variantTwo->getNumber('test', -1));

        $holdoutUser = null;
        foreach (range(0, 200) as $index) {
            $loginId = 'layer-holdout-user-' . $index;
            $result = $layerHoldoutCore->evaluate(new User('', $loginId), 'ARQjYcfVPI', ABCore::TYPE_EXPERIMENT);
            if ($result->variantId === 'holdout') {
                $holdoutUser = $loginId;
                break;
            }
        }

        self::assertNotNull($holdoutUser);
    }

    public function testExperimentHoldoutRateAndVariantsStayInExpectedRange(): void
    {
        $core = new ABCore(FixtureLoader::loadStorageFromJson(
            dirname(__DIR__) . '/Fixtures/ab/exp/holdout.json'
        ));

        $totalUsers = 1000;
        $holdoutCount = 0;
        $variantCount = [];
        for ($index = 0; $index < $totalUsers; $index++) {
            $result = $core->evaluate(
                new User('', 'holdout-user-' . $index),
                'BKduZnxYPD',
                ABCore::TYPE_EXPERIMENT
            );
            self::assertNotNull($result->variantId);
            if ($result->variantId === 'holdout') {
                $holdoutCount++;
                continue;
            }
            $variantCount[$result->variantId] = ($variantCount[$result->variantId] ?? 0) + 1;
        }

        self::assertGreaterThan(0, $variantCount['v1'] ?? 0);
        self::assertGreaterThan(0, $variantCount['v2'] ?? 0);
        self::assertEqualsWithDelta(0.10, $holdoutCount / $totalUsers, 0.03);
    }

    public function testExperimentLayerWithHoldoutRateStaysNearExpectedRange(): void
    {
        $core = new ABCore(FixtureLoader::loadStorageFromJson(
            dirname(__DIR__) . '/Fixtures/ab/exp/layer_with_holdout.json'
        ));

        $totalUsers = 1000;
        $holdoutCount = 0;
        $variantCount = [];
        for ($index = 0; $index < $totalUsers; $index++) {
            $result = $core->evaluate(
                new User('', 'layer-holdout-user-' . $index),
                'ARQjYcfVPI',
                ABCore::TYPE_EXPERIMENT
            );
            self::assertNotNull($result->variantId);
            if ($result->variantId === 'holdout') {
                $holdoutCount++;
                continue;
            }
            $variantCount[$result->variantId] = ($variantCount[$result->variantId] ?? 0) + 1;
        }

        self::assertGreaterThan(0, $variantCount['v1'] ?? 0);
        self::assertGreaterThan(0, $variantCount['v2'] ?? 0);
        self::assertEqualsWithDelta(0.10, $holdoutCount / $totalUsers, 0.03);
    }

    public function testExperimentTrafficRolloutCanExcludeUsers(): void
    {
        $core = new ABCore(FixtureLoader::loadStorageFromJson(
            dirname(__DIR__) . '/Fixtures/ab/exp/public.json'
        ));

        $inTraffic = null;
        $outTraffic = null;
        foreach (['user0', 'user1', 'user2', 'user3', 'user4', 'alice', 'bob', 'charlie', 'david', 'eve'] as $loginId) {
            $result = $core->evaluate(new User('', $loginId), 'New_Experiment', ABCore::TYPE_EXPERIMENT);
            if ($result->variantId !== null && $inTraffic === null) {
                $inTraffic = $loginId;
            }
            if ($result->variantId === null && $outTraffic === null) {
                $outTraffic = $loginId;
            }
            if ($inTraffic !== null && $outTraffic !== null) {
                break;
            }
        }

        self::assertNotNull($inTraffic);
        self::assertNotNull($outTraffic);
    }

    public function testExperimentTargetRequiresMatchingVersion(): void
    {
        $core = new ABCore(FixtureLoader::loadStorageFromJson(
            dirname(__DIR__) . '/Fixtures/ab/exp/target.json'
        ));

        $blocked = $core->evaluate(
            new User('', 'user1', Properties::create()->set('$app_version', '9.0')),
            'TargetExperiment',
            ABCore::TYPE_EXPERIMENT
        );
        self::assertNull($blocked->variantId);

        $allowed = null;
        foreach (['user0', 'user1', 'user2', 'user3', 'user4', 'user5'] as $loginId) {
            $result = $core->evaluate(
                new User('', $loginId, Properties::create()->set('$app_version', '10.0')),
                'TargetExperiment',
                ABCore::TYPE_EXPERIMENT
            );
            if ($result->variantId !== null) {
                $allowed = $result;
                break;
            }
        }

        self::assertNotNull($allowed);
        self::assertContains($allowed->variantId, ['v1', 'v2']);
        self::assertContains($allowed->getNumber('test', -1), [0.0, 1.0]);
    }

    public function testExperimentReleaseOverridesAllUsersToVariantTwo(): void
    {
        $core = new ABCore(FixtureLoader::loadStorageFromJson(
            dirname(__DIR__) . '/Fixtures/ab/exp/release.json'
        ));

        foreach (['user1', 'user2', 'alice', 'bob'] as $loginId) {
            $result = $core->evaluate(new User('', $loginId), 'TargetExperiment', ABCore::TYPE_EXPERIMENT);
            self::assertSame('v2', $result->variantId);
            self::assertSame(1.0, $result->getNumber('test', -1));
        }
    }

    public function testExp001ReleaseOverridesAllUsersToVariantTwo(): void
    {
        $this->assertNotFalse(strpos($this->name(), 'Exp001'));
        $this->testExperimentReleaseOverridesAllUsersToVariantTwo();
    }

    public function testExp002ExperimentPublicAssignsVariantAndPayload(): void
    {
        $this->assertNotFalse(strpos($this->name(), 'Exp002'));
        $this->testExperimentPublicAssignsVariantAndPayload();
    }

    public function testExp003ExperimentGateDependencyPassAndFail(): void
    {
        $this->assertNotFalse(strpos($this->name(), 'Exp003'));
        $this->testExperimentGateDependencyPassAndFail();
    }

    public function testExp004ExperimentHoldoutCanReturnHoldoutVariant(): void
    {
        $this->assertNotFalse(strpos($this->name(), 'Exp004'));
        $this->testExperimentHoldoutCanReturnHoldoutVariant();
    }

    public function testExp005ExperimentLayerAndLayerHoldout(): void
    {
        $this->assertNotFalse(strpos($this->name(), 'Exp005'));
        $this->testExperimentLayerAndLayerHoldout();
    }

    public function testExp006ExperimentTargetRequiresMatchingVersion(): void
    {
        $this->assertNotFalse(strpos($this->name(), 'Exp006'));
        $this->testExperimentTargetRequiresMatchingVersion();
    }

    public function testExp007ExperimentStickyUsesCacheAndPersistsResult(): void
    {
        $this->assertNotFalse(strpos($this->name(), 'Exp007'));
        $this->testExperimentStickyUsesCacheAndPersistsResult();
    }
}
