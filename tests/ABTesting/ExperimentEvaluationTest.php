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
}
