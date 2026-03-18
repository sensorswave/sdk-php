<?php

declare(strict_types=1);

namespace SensorsWave\Tests\ABTesting;

use PHPUnit\Framework\TestCase;
use SensorsWave\ABTesting\ABCore;
use SensorsWave\Model\User;
use SensorsWave\Model\Properties;
use SensorsWave\Tests\Support\FixtureLoader;

final class GateEvaluationTest extends TestCase
{
    public function testGatePublicRollout(): void
    {
        $core = new ABCore(FixtureLoader::loadStorageFromJson(
            dirname(__DIR__) . '/Fixtures/ab/gate/public.json'
        ));

        $pass = $core->evaluate(new User('', 'user-pass'), 'TestSpec', ABCore::TYPE_GATE);
        $fail = $core->evaluate(new User('', 'user-fail'), 'TestSpec', ABCore::TYPE_GATE);

        self::assertTrue($pass->checkFeatureGate());
        self::assertFalse($fail->checkFeatureGate());
    }

    public function testGateAnyOfSensitiveProps(): void
    {
        $core = new ABCore(FixtureLoader::loadStorageFromJson(
            dirname(__DIR__) . '/Fixtures/ab/gate/anyof_sensitive.json'
        ));

        $missing = $core->evaluate(new User('', 'user-pass'), 'TestSpec', ABCore::TYPE_GATE);
        $wrong = $core->evaluate(
            new User('', 'user-pass', Properties::create()->set('$browser_name', 'Safari')),
            'TestSpec',
            ABCore::TYPE_GATE
        );
        $correct = $core->evaluate(
            new User('', 'user-pass', Properties::create()->set('$browser_name', 'Chrome')),
            'TestSpec',
            ABCore::TYPE_GATE
        );

        self::assertFalse($missing->checkFeatureGate());
        self::assertFalse($wrong->checkFeatureGate());
        self::assertTrue($correct->checkFeatureGate());
    }

    public function testGateNoneOfInsensitiveProps(): void
    {
        $core = new ABCore(FixtureLoader::loadStorageFromJson(
            dirname(__DIR__) . '/Fixtures/ab/gate/noneof_insentive.json'
        ));

        $blocked = $core->evaluate(
            new User('', 'user-pass', Properties::create()->set('$browser_name', 'chrome')),
            'TestSpec',
            ABCore::TYPE_GATE
        );
        $allowed = $core->evaluate(
            new User('', 'user-pass', Properties::create()->set('$browser_name', 'Edge')),
            'TestSpec',
            ABCore::TYPE_GATE
        );

        self::assertFalse($blocked->checkFeatureGate());
        self::assertTrue($allowed->checkFeatureGate());
    }

    public function testGateIsNullAndIsNotNull(): void
    {
        $isNullCore = new ABCore(FixtureLoader::loadStorageFromJson(
            dirname(__DIR__) . '/Fixtures/ab/gate/isnull.json'
        ));
        $isNotNullCore = new ABCore(FixtureLoader::loadStorageFromJson(
            dirname(__DIR__) . '/Fixtures/ab/gate/isnotnull.json'
        ));

        $nullResult = $isNullCore->evaluate(new User('', 'user-pass'), 'TestSpec', ABCore::TYPE_GATE);
        $notNullResult = $isNotNullCore->evaluate(
            new User('', 'user-pass', Properties::create()->set('$browser_name', 'Chrome')),
            'TestSpec',
            ABCore::TYPE_GATE
        );

        self::assertTrue($nullResult->checkFeatureGate());
        self::assertTrue($notNullResult->checkFeatureGate());
    }

    public function testDisabledAndMissingKeyReturnFalse(): void
    {
        $disabledCore = new ABCore(FixtureLoader::loadStorageFromJson(
            dirname(__DIR__) . '/Fixtures/ab/gate/disable.json'
        ));
        $publicCore = new ABCore(FixtureLoader::loadStorageFromJson(
            dirname(__DIR__) . '/Fixtures/ab/gate/public.json'
        ));

        $disabled = $disabledCore->evaluate(new User('', 'user-pass'), 'TestSpec', ABCore::TYPE_GATE);
        $missing = $publicCore->evaluate(new User('', 'user-pass'), 'missing', ABCore::TYPE_GATE);

        self::assertFalse($disabled->checkFeatureGate());
        self::assertFalse($missing->checkFeatureGate());
    }

    public function testGateNumberBooleanAndEqualityOperators(): void
    {
        $gteCore = new ABCore(FixtureLoader::loadStorageFromJson(
            dirname(__DIR__) . '/Fixtures/ab/gate/gte_number.json'
        ));
        $ltCore = new ABCore(FixtureLoader::loadStorageFromJson(
            dirname(__DIR__) . '/Fixtures/ab/gate/lt_number.json'
        ));
        $lteCore = new ABCore(FixtureLoader::loadStorageFromJson(
            dirname(__DIR__) . '/Fixtures/ab/gate/lte_number.json'
        ));
        $trueCore = new ABCore(FixtureLoader::loadStorageFromJson(
            dirname(__DIR__) . '/Fixtures/ab/gate/is_true.json'
        ));
        $falseCore = new ABCore(FixtureLoader::loadStorageFromJson(
            dirname(__DIR__) . '/Fixtures/ab/gate/is_false.json'
        ));
        $eqCore = new ABCore(FixtureLoader::loadStorageFromJson(
            dirname(__DIR__) . '/Fixtures/ab/gate/eq.json'
        ));
        $neqCore = new ABCore(FixtureLoader::loadStorageFromJson(
            dirname(__DIR__) . '/Fixtures/ab/gate/neq_number.json'
        ));

        self::assertTrue($gteCore->evaluate(
            new User('', 'user-1', Properties::create()->set('user_age', 18)),
            'GTE_Number_Gate',
            ABCore::TYPE_GATE
        )->checkFeatureGate());
        self::assertTrue($ltCore->evaluate(
            new User('', 'user-1', Properties::create()->set('user_age', 20)),
            'LT_Number_Gate',
            ABCore::TYPE_GATE
        )->checkFeatureGate());
        self::assertTrue($lteCore->evaluate(
            new User('', 'user-1', Properties::create()->set('user_score', 100)),
            'LTE_Number_Gate',
            ABCore::TYPE_GATE
        )->checkFeatureGate());
        self::assertTrue($trueCore->evaluate(
            new User('', 'user-1', Properties::create()->set('is_premium', true)),
            'Is_True_Gate',
            ABCore::TYPE_GATE
        )->checkFeatureGate());
        self::assertTrue($falseCore->evaluate(
            new User('', 'user-1', Properties::create()->set('is_banned', false)),
            'Is_False_Gate',
            ABCore::TYPE_GATE
        )->checkFeatureGate());
        self::assertTrue($eqCore->evaluate(
            new User('', 'user-1', Properties::create()->set('country', 'US')),
            'EQ_Gate',
            ABCore::TYPE_GATE
        )->checkFeatureGate());
        self::assertTrue($neqCore->evaluate(
            new User('', 'user-1', Properties::create()->set('level', 1)),
            'NEQ_Number_Gate',
            ABCore::TYPE_GATE
        )->checkFeatureGate());
    }

    public function testGateVersionAndTimeOperators(): void
    {
        $gtVersion = new ABCore(FixtureLoader::loadStorageFromJson(
            dirname(__DIR__) . '/Fixtures/ab/gate/greater_version.json'
        ));
        $gteVersion = new ABCore(FixtureLoader::loadStorageFromJson(
            dirname(__DIR__) . '/Fixtures/ab/gate/greater_equal_version.json'
        ));
        $ltVersion = new ABCore(FixtureLoader::loadStorageFromJson(
            dirname(__DIR__) . '/Fixtures/ab/gate/less_version.json'
        ));
        $lteVersion = new ABCore(FixtureLoader::loadStorageFromJson(
            dirname(__DIR__) . '/Fixtures/ab/gate/less_equal_version.json'
        ));
        $eqVersion = new ABCore(FixtureLoader::loadStorageFromJson(
            dirname(__DIR__) . '/Fixtures/ab/gate/equal_version.json'
        ));
        $beforeCore = new ABCore(FixtureLoader::loadStorageFromJson(
            dirname(__DIR__) . '/Fixtures/ab/gate/before.json'
        ));
        $afterCore = new ABCore(FixtureLoader::loadStorageFromJson(
            dirname(__DIR__) . '/Fixtures/ab/gate/after.json'
        ));

        self::assertTrue($gtVersion->evaluate(
            new User('', 'user-1', Properties::create()->set('$app_version', '10.1')),
            'TestSpec',
            ABCore::TYPE_GATE
        )->checkFeatureGate());
        self::assertTrue($gteVersion->evaluate(
            new User('', 'user-1', Properties::create()->set('$app_version', '10.0')),
            'TestSpec',
            ABCore::TYPE_GATE
        )->checkFeatureGate());
        self::assertTrue($ltVersion->evaluate(
            new User('', 'user-1', Properties::create()->set('$app_version', '9.9')),
            'TestSpec',
            ABCore::TYPE_GATE
        )->checkFeatureGate());
        self::assertTrue($lteVersion->evaluate(
            new User('', 'user-1', Properties::create()->set('$app_version', '10.0')),
            'TestSpec',
            ABCore::TYPE_GATE
        )->checkFeatureGate());
        self::assertTrue($eqVersion->evaluate(
            new User('', 'user-1', Properties::create()->set('$app_version', '10.0')),
            'TestSpec',
            ABCore::TYPE_GATE
        )->checkFeatureGate());
        self::assertTrue($beforeCore->evaluate(
            new User('', 'user-1', Properties::create()->set('user_created_at', '2023-12-31T23:59:59Z')),
            'Before_Time_Gate',
            ABCore::TYPE_GATE
        )->checkFeatureGate());
        self::assertTrue($afterCore->evaluate(
            new User('', 'user-1', Properties::create()->set('registration_date', '2023-06-01T00:00:00Z')),
            'After_Time_Gate',
            ABCore::TYPE_GATE
        )->checkFeatureGate());
    }

    public function testGateCustomFieldOverrideAndAnonIdSubject(): void
    {
        $customCore = new ABCore(FixtureLoader::loadStorageFromJson(
            dirname(__DIR__) . '/Fixtures/ab/gate/custom_field.json'
        ));
        $overrideIdCore = new ABCore(FixtureLoader::loadStorageFromJson(
            dirname(__DIR__) . '/Fixtures/ab/gate/override_id.json'
        ));
        $overrideConditionCore = new ABCore(FixtureLoader::loadStorageFromJson(
            dirname(__DIR__) . '/Fixtures/ab/gate/override_condition.json'
        ));
        $anonCore = new ABCore(FixtureLoader::loadStorageFromJson(
            dirname(__DIR__) . '/Fixtures/ab/gate/anon_id.json'
        ));

        self::assertTrue($customCore->evaluate(
            new User('', 'user-1', Properties::create()->set('age', 20)),
            'TestSpec',
            ABCore::TYPE_GATE
        )->checkFeatureGate());
        self::assertTrue($overrideIdCore->evaluate(
            new User('', 'login-id-example-2'),
            'TestSpec',
            ABCore::TYPE_GATE
        )->checkFeatureGate());
        self::assertTrue($overrideConditionCore->evaluate(
            new User('', 'user-1', Properties::create()->set('$country', 'China')),
            'TestSpec',
            ABCore::TYPE_GATE
        )->checkFeatureGate());
        self::assertTrue($anonCore->evaluate(
            new User('anon-1', ''),
            'AnonIdTest',
            ABCore::TYPE_GATE
        )->checkFeatureGate());
    }
}
