<?php

declare(strict_types=1);

namespace SensorsWave\Tests\ABTesting;

use PHPUnit\Framework\TestCase;
use SensorsWave\ABTesting\ABCore;
use SensorsWave\Model\User;
use SensorsWave\Tests\Support\FixtureLoader;

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
}
