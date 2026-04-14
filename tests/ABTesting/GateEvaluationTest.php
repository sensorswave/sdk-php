<?php

declare(strict_types=1);

namespace SensorsWave\Tests\ABTesting;

use PHPUnit\Framework\TestCase;
use SensorsWave\ABTesting\ABCore;
use RuntimeException;
use SensorsWave\ABTesting\Model\ABEnv;
use SensorsWave\ABTesting\Model\ABSpec;
use SensorsWave\ABTesting\Model\Condition;
use SensorsWave\ABTesting\Model\Rule;
use SensorsWave\ABTesting\Storage;
use SensorsWave\Model\Properties;
use SensorsWave\Model\User;
use SensorsWave\Tests\Support\FixtureLoader;
use SensorsWave\Tests\Support\MemoryStickyHandler;

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

        self::assertFalse($isNullCore->evaluate(
            new User('', 'user-pass', Properties::create()->set('$browser_name', '')),
            'TestSpec',
            ABCore::TYPE_GATE
        )->checkFeatureGate());
        self::assertTrue($isNotNullCore->evaluate(
            new User('', 'user-pass', Properties::create()->set('$browser_name', '')),
            'TestSpec',
            ABCore::TYPE_GATE
        )->checkFeatureGate());
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

    public function testGateVersionNeqAndEmptyRulesFallbackToFalse(): void
    {
        $versionNeqCore = new ABCore(FixtureLoader::loadStorageFromJson(
            dirname(__DIR__) . '/Fixtures/ab/gate/not_equal.json'
        ));
        $emptyRulesCore = new ABCore(FixtureLoader::loadStorageFromJson(
            dirname(__DIR__) . '/Fixtures/ab/gate/missing_gate_rules.json'
        ));

        self::assertFalse($versionNeqCore->evaluate(
            new User('', 'user-pass', Properties::create()->set('$app_version', '10.0')),
            'TestSpec',
            ABCore::TYPE_GATE
        )->checkFeatureGate());
        self::assertTrue($versionNeqCore->evaluate(
            new User('', 'user-pass', Properties::create()->set('$app_version', '10.1')),
            'TestSpec',
            ABCore::TYPE_GATE
        )->checkFeatureGate());
        self::assertFalse($emptyRulesCore->evaluate(
            new User('', 'user-any'),
            'TestSpec',
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

        self::assertTrue($beforeCore->evaluate(
            new User('', 'user-missing'),
            'Before_Time_Gate',
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

        self::assertTrue($customCore->evaluate(
            new User('', 'user-1', Properties::create()->set('time', '2025-11-18T06:00:40Z')),
            'TestSpec',
            ABCore::TYPE_GATE
        )->checkFeatureGate());
        self::assertFalse($customCore->evaluate(
            new User('', 'user-pass', Properties::create()
                ->set('$app_version', '9.0')
                ->set('$browser_name', 'Firefox')
                ->set('age', 10)),
            'TestSpec',
            ABCore::TYPE_GATE
        )->checkFeatureGate());
    }

    public function testEvaluateAllReturnsAllGateResultsIncludingFailures(): void
    {
        $core = new ABCore(FixtureLoader::loadStorageFromJson(
            dirname(__DIR__) . '/Fixtures/ab/gate/multi_gates.json'
        ));

        $results = $core->evaluateAll(new User(
            '',
            'user5',
            Properties::create()
                ->set('$app_version', '10.5')
                ->set('$country', 'JP')
        ));

        self::assertCount(3, $results);

        $resultMap = [];
        foreach ($results as $result) {
            $resultMap[$result->key] = $result;
        }

        self::assertArrayHasKey('Gate_A', $resultMap);
        self::assertArrayHasKey('Gate_B', $resultMap);
        self::assertArrayHasKey('Gate_C', $resultMap);
        self::assertTrue($resultMap['Gate_A']->checkFeatureGate());
        self::assertTrue($resultMap['Gate_B']->checkFeatureGate());
        self::assertFalse($resultMap['Gate_C']->checkFeatureGate());
        self::assertSame('fail', $resultMap['Gate_C']->variantId);
    }

    public function testEvaluateAllMultipleUsersPreservesPassFailCombinations(): void
    {
        $core = new ABCore(FixtureLoader::loadStorageFromJson(
            dirname(__DIR__) . '/Fixtures/ab/gate/multi_gates.json'
        ));

        $testCases = [
            [
                'user' => new User('', 'user1', Properties::create()->set('$app_version', '10.0')->set('$country', 'CN')->set('is_premium', true)),
                'expected' => ['Gate_A' => true, 'Gate_B' => true, 'Gate_C' => true],
                'hitCount' => 3,
            ],
            [
                'user' => new User('', 'user5', Properties::create()->set('$app_version', '10.5')->set('$country', 'JP')),
                'expected' => ['Gate_A' => true, 'Gate_B' => true, 'Gate_C' => false],
                'hitCount' => 2,
            ],
            [
                'user' => new User('', 'user6', Properties::create()->set('$app_version', '9.0')->set('$country', 'KR')->set('is_premium', false)),
                'expected' => ['Gate_A' => false, 'Gate_B' => false, 'Gate_C' => false],
                'hitCount' => 0,
            ],
        ];

        foreach ($testCases as $testCase) {
            $results = $core->evaluateAll($testCase['user']);
            self::assertCount(3, $results);

            $resultMap = [];
            foreach ($results as $result) {
                $resultMap[$result->key] = $result;
            }

            $hitCount = 0;
            foreach ($testCase['expected'] as $key => $expectedPass) {
                self::assertArrayHasKey($key, $resultMap);
                self::assertSame($expectedPass, $resultMap[$key]->checkFeatureGate());
                self::assertSame($expectedPass ? 'pass' : 'fail', $resultMap[$key]->variantId);
                if ($resultMap[$key]->checkFeatureGate()) {
                    $hitCount++;
                }
            }

            self::assertSame($testCase['hitCount'], $hitCount);
        }
    }

    public function testGateNoneOfSensitivePropsAndReleaseGate(): void
    {
        $noneOfSensitiveCore = new ABCore(FixtureLoader::loadStorageFromJson(
            dirname(__DIR__) . '/Fixtures/ab/gate/noneof_sensitive.json'
        ));
        $releaseCore = new ABCore(FixtureLoader::loadStorageFromJson(
            dirname(__DIR__) . '/Fixtures/ab/gate/release.json'
        ));

        self::assertTrue($noneOfSensitiveCore->evaluate(
            new User('', 'user-pass'),
            'TestSpec',
            ABCore::TYPE_GATE
        )->checkFeatureGate());
        self::assertFalse($noneOfSensitiveCore->evaluate(
            new User('', 'user-pass', Properties::create()->set('$browser_name', 'Chrome')),
            'TestSpec',
            ABCore::TYPE_GATE
        )->checkFeatureGate());
        self::assertTrue($noneOfSensitiveCore->evaluate(
            new User('', 'user-pass', Properties::create()->set('$browser_name', 'chrome')),
            'TestSpec',
            ABCore::TYPE_GATE
        )->checkFeatureGate());

        self::assertTrue($releaseCore->evaluate(
            new User('', 'user-pass'),
            'ReleaseGate',
            ABCore::TYPE_GATE
        )->checkFeatureGate());
        self::assertTrue($releaseCore->evaluate(
            new User('', 'user-other'),
            'ReleaseGate',
            ABCore::TYPE_GATE
        )->checkFeatureGate());
    }

    public function testGateStickyUsesCacheAndPersistsPassResult(): void
    {
        $handler = new MemoryStickyHandler();
        $handler->data['25-user-cache'] = json_encode(['v' => 'pass'], JSON_THROW_ON_ERROR);

        $core = new ABCore(
            FixtureLoader::loadStorageFromJson(dirname(__DIR__) . '/Fixtures/ab/gate/sticky.json'),
            $handler
        );

        $cached = $core->evaluate(
            new User('', 'user-cache', Properties::create()->set('is_premium', false)),
            'Sticky_Is_True_Gate',
            ABCore::TYPE_GATE
        );
        self::assertTrue($cached->checkFeatureGate());

        $fresh = $core->evaluate(
            new User('', 'user-new', Properties::create()->set('is_premium', true)),
            'Sticky_Is_True_Gate',
            ABCore::TYPE_GATE
        );
        self::assertTrue($fresh->checkFeatureGate());
        self::assertArrayHasKey('25-user-new', $handler->data);
    }

    public function testGateHoldoutAndDependentGateFail(): void
    {
        $holdoutCore = new ABCore(FixtureLoader::loadStorageFromJson(
            dirname(__DIR__) . '/Fixtures/ab/gate/holdout.json'
        ));
        $gateFailCore = new ABCore(FixtureLoader::loadStorageFromJson(
            dirname(__DIR__) . '/Fixtures/ab/gate/gate_fail.json'
        ));

        self::assertFalse($holdoutCore->evaluate(
            new User('', 'user1', Properties::create()->set('$app_version', '10.0')),
            'holdout_gate',
            ABCore::TYPE_GATE
        )->checkFeatureGate());
        self::assertTrue($holdoutCore->evaluate(
            new User('', 'user2', Properties::create()->set('$app_version', '10.1')),
            'holdout_gate',
            ABCore::TYPE_GATE
        )->checkFeatureGate());

        self::assertTrue($gateFailCore->evaluate(
            new User('', 'user-pass', Properties::create()->set('country', 'CN')),
            'Gate_Fail_Dependent',
            ABCore::TYPE_GATE
        )->checkFeatureGate());
        self::assertTrue($gateFailCore->evaluate(
            new User('', 'user-pass', Properties::create()->set('country', 'US')),
            'Gate_Fail_Dependent',
            ABCore::TYPE_GATE
        )->checkFeatureGate());
    }

    public function testGateComplicateRuleChain(): void
    {
        $core = new ABCore(FixtureLoader::loadStorageFromJson(
            dirname(__DIR__) . '/Fixtures/ab/gate/complicate.json'
        ));

        self::assertTrue($core->evaluate(
            new User('any', 'other', Properties::create()
                ->set('$app_version', '9.0')
                ->set('$browser_name', 'Firefox')
                ->set('age', 5)
            ),
            'AnonIdTest',
            ABCore::TYPE_GATE
        )->checkFeatureGate());

        self::assertTrue($core->evaluate(
            new User('any', 'other', Properties::create()
                ->set('$app_version', '9.0')
                ->set('$browser_name', 'Firefox')
                ->set('age', 5)
                ->set('$device_model', 'Pixel')
                ->set('$country', 'US')
            ),
            'AnonIdTest',
            ABCore::TYPE_GATE
        )->checkFeatureGate());

        self::assertFalse($core->evaluate(
            new User('any', 'other', Properties::create()
                ->set('$app_version', '9.0')
                ->set('$browser_name', 'Firefox')
                ->set('age', 5)
                ->set('$device_model', 'Pixel')
            ),
            'AnonIdTest',
            ABCore::TYPE_GATE
        )->checkFeatureGate());
    }

    public function testInvalidCommonConditionRaisesRuntimeException(): void
    {
        $core = new ABCore(new Storage(
            1,
            new ABEnv(),
            [
                'broken_gate' => new ABSpec(
                    1,
                    'broken_gate',
                    'Broken Gate',
                    ABCore::TYPE_GATE,
                    '',
                    'LOGIN_ID',
                    true,
                    false,
                    '',
                    1,
                    false,
                    [
                        'GATE' => [
                            new Rule(
                                'invalid-common',
                                'r1',
                                '',
                                100.0,
                                [new Condition('COMMON', 'unknown', 'IS_TRUE', null)],
                                null
                            ),
                        ],
                    ],
                    []
                ),
            ]
        ));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unknown common field');
        $core->evaluate(new User('', 'u'), 'broken_gate', ABCore::TYPE_GATE);
    }

    public function testStickyWriteExceptionPropagates(): void
    {
        $handler = new class implements \SensorsWave\Contract\StickyHandlerInterface {
            public function getStickyResult(string $key): ?string
            {
                return null;
            }

            public function setStickyResult(string $key, string $result): void
            {
                throw new RuntimeException('sticky write failed');
            }
        };

        $core = new ABCore(FixtureLoader::loadStorageFromJson(
            dirname(__DIR__) . '/Fixtures/ab/gate/sticky.json'
        ), $handler);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('sticky write failed');
        $core->evaluate(
            new User('', 'user-fail', Properties::create()->set('is_premium', true)),
            'Sticky_Is_True_Gate',
            ABCore::TYPE_GATE
        );
    }

    public function testInvalidBucketSetConditionRaisesRuntimeException(): void
    {
        $core = new ABCore(new Storage(
            1,
            new ABEnv(),
            [
                'broken_bucket_gate' => new ABSpec(
                    2,
                    'broken_bucket_gate',
                    'Broken Bucket Gate',
                    ABCore::TYPE_GATE,
                    '',
                    'LOGIN_ID',
                    true,
                    false,
                    '',
                    1,
                    false,
                    [
                        'GATE' => [
                            new Rule(
                                'invalid-bucket-set',
                                'r2',
                                '',
                                100.0,
                                [new Condition('BUCKET', 'salt', 'BUCKET_SET', 123)],
                                null
                            ),
                        ],
                    ],
                    []
                ),
            ]
        ));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('bucket_set requires string salt and bitmap');
        $core->evaluate(new User('', 'u'), 'broken_bucket_gate', ABCore::TYPE_GATE);
    }

    public function testGate019DisabledAndMissingKeyReturnFalse(): void
    {
        $this->assertNotFalse(strpos($this->name(), 'Gate019'));
        $this->testDisabledAndMissingKeyReturnFalse();
    }

    public function testGate021GateHoldoutAndDependentGateFail(): void
    {
        $this->assertNotFalse(strpos($this->name(), 'Gate021'));
        $this->testGateHoldoutAndDependentGateFail();
    }

    public function testGate022GateComplicateRuleChain(): void
    {
        $this->assertNotFalse(strpos($this->name(), 'Gate022'));
        $this->testGateComplicateRuleChain();
    }

    public function testGate026InvalidCommonConditionRaisesRuntimeException(): void
    {
        $this->assertNotFalse(strpos($this->name(), 'Gate026'));
        $this->testInvalidCommonConditionRaisesRuntimeException();
    }

    public function testGate027StickyWriteExceptionPropagates(): void
    {
        $this->assertNotFalse(strpos($this->name(), 'Gate027'));
        $this->testStickyWriteExceptionPropagates();
    }

    public function testGate028GateVersionAndTimeOperators(): void
    {
        $this->assertNotFalse(strpos($this->name(), 'Gate028'));
        $this->testGateVersionAndTimeOperators();
    }

    public function testGate038GateHoldoutAndDependentGateFail(): void
    {
        $this->assertNotFalse(strpos($this->name(), 'Gate038'));
        $this->testGateHoldoutAndDependentGateFail();
    }

    public function testGateFirstMatchWinsVipPassesViaFirstRule(): void
    {
        $core = new ABCore(FixtureLoader::loadStorageFromJson(
            dirname(__DIR__) . '/Fixtures/ab/gate/first_match_wins.json'
        ));

        $result = $core->evaluate(new User('', 'vip-user-1'), 'gate_first_match', ABCore::TYPE_GATE);

        self::assertTrue($result->checkFeatureGate());
        self::assertSame('gate-vip-rule', $result->decisionRuleId);
    }

    public function testGateFirstMatchWinsVipPlusMemberStillMatchesFirst(): void
    {
        $core = new ABCore(FixtureLoader::loadStorageFromJson(
            dirname(__DIR__) . '/Fixtures/ab/gate/first_match_wins.json'
        ));

        $result = $core->evaluate(
            new User('', 'vip-user-2', Properties::create()->set('is_member', true)),
            'gate_first_match',
            ABCore::TYPE_GATE
        );

        self::assertTrue($result->checkFeatureGate());
        self::assertSame('gate-vip-rule', $result->decisionRuleId);
    }

    public function testGateFirstMatchWinsMemberPassesViaSecondRule(): void
    {
        $core = new ABCore(FixtureLoader::loadStorageFromJson(
            dirname(__DIR__) . '/Fixtures/ab/gate/first_match_wins.json'
        ));

        $result = $core->evaluate(
            new User('', 'regular-member', Properties::create()->set('is_member', true)),
            'gate_first_match',
            ABCore::TYPE_GATE
        );

        self::assertTrue($result->checkFeatureGate());
        self::assertSame('gate-member-rule', $result->decisionRuleId);
    }

    public function testGateFirstMatchWinsPlainUserMatchesThirdRuleZeroRolloutFails(): void
    {
        $core = new ABCore(FixtureLoader::loadStorageFromJson(
            dirname(__DIR__) . '/Fixtures/ab/gate/first_match_wins.json'
        ));

        // Rule 1 (VIP): not in list → skip. Rule 2 (Member): not set → skip.
        // Rule 3 (Public): IS_TRUE matches → rollout=0 → fail. Stops here.
        $result = $core->evaluate(new User('', 'plain-user'), 'gate_first_match', ABCore::TYPE_GATE);

        self::assertFalse($result->checkFeatureGate());
        self::assertSame('gate-zero-rollout-rule', $result->decisionRuleId);
    }

    public function testGateFirstMatchWinsNonMemberMatchesThirdRuleZeroRolloutFails(): void
    {
        $core = new ABCore(FixtureLoader::loadStorageFromJson(
            dirname(__DIR__) . '/Fixtures/ab/gate/first_match_wins.json'
        ));

        // is_member=false → Rule 2 skipped → Rule 3 matches → rollout=0 → fail
        $result = $core->evaluate(
            new User('', 'non-member', Properties::create()->set('is_member', false)),
            'gate_first_match',
            ABCore::TYPE_GATE
        );

        self::assertFalse($result->checkFeatureGate());
        self::assertSame('gate-zero-rollout-rule', $result->decisionRuleId);
    }

    /**
     * 循环依赖的 gate 不应导致栈溢出，应安全返回 fail。
     */
    public function testGateCircularDependencyReturnsFalseWithoutStackOverflow(): void
    {
        $core = new ABCore(FixtureLoader::loadStorageFromJson(
            dirname(__DIR__) . '/Fixtures/ab/gate/gate_circular.json'
        ));

        $resultA = $core->evaluate(new User('', 'user-1'), 'Gate_A', ABCore::TYPE_GATE);
        $resultB = $core->evaluate(new User('', 'user-1'), 'Gate_B', ABCore::TYPE_GATE);

        // 循环依赖达到最大递归深度后返回空结果，gate 失败
        self::assertFalse($resultA->checkFeatureGate());
        self::assertFalse($resultB->checkFeatureGate());
    }
}
