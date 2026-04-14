<?php

declare(strict_types=1);

namespace SensorsWave\Tests\ABTesting;

use PHPUnit\Framework\TestCase;
use SensorsWave\ABTesting\ABCore;
use SensorsWave\ABTesting\Model\ABEnv;
use SensorsWave\ABTesting\Model\ABSpec;
use SensorsWave\ABTesting\Model\Condition;
use SensorsWave\ABTesting\Model\Rule;
use SensorsWave\ABTesting\Storage;
use SensorsWave\Model\Properties;
use SensorsWave\Model\User;
use SensorsWave\Tests\Support\MemoryStickyHandler;

final class DecisionRuleTrackingTest extends TestCase
{
    /**
     * Gate pass: conditions match + rollout=100 -> decisionRuleId == rule id.
     */
    public function testGatePassSetsDecisionRuleId(): void
    {
        $core = new ABCore($this->buildStorage([
            'GATE' => [
                new Rule('gate-rule', 'rule-100', '', 100.0, [
                    new Condition('COMMON', 'public', 'IS_TRUE', null),
                ], null),
            ],
        ]));

        $result = $core->evaluate(new User('', 'user1'), 'test_gate', ABCore::TYPE_GATE);

        self::assertTrue($result->checkFeatureGate());
        self::assertSame('rule-100', $result->decisionRuleId);
    }

    /**
     * Gate rollout rejection: conditions match but rollout fails -> decisionRuleId == rule id.
     */
    public function testGateRolloutRejectionSetsDecisionRuleId(): void
    {
        // rollout=0.01 (1%) — sha256("user-miss.salt-tiny") is almost certainly >= 1
        $core = new ABCore($this->buildStorage([
            'GATE' => [
                new Rule('gate-rule', 'rule-tiny', 'salt-tiny', 0.01, [
                    new Condition('COMMON', 'public', 'IS_TRUE', null),
                ], null),
            ],
        ]));

        $result = $core->evaluate(new User('', 'user-miss'), 'test_gate', ABCore::TYPE_GATE);

        // Conditions matched (public=true) so matched=true. Whether pass or fail,
        // decisionRuleId must be set because the gate rule matched.
        self::assertSame('rule-tiny', $result->decisionRuleId);
    }

    /**
     * Zero rollout + conditions match -> decisionRuleId == rule id.
     */
    public function testZeroRolloutConditionsMatchSetsDecisionRuleId(): void
    {
        $core = new ABCore($this->buildStorage([
            'GATE' => [
                new Rule('gate-rule', 'rule-zero', '', 0.0, [
                    new Condition('COMMON', 'public', 'IS_TRUE', null),
                ], null),
            ],
        ]));

        $result = $core->evaluate(new User('', 'user1'), 'test_gate', ABCore::TYPE_GATE);

        self::assertFalse($result->checkFeatureGate());
        self::assertSame('rule-zero', $result->decisionRuleId);
    }

    /**
     * Zero rollout + conditions don't match -> decisionRuleId == null.
     */
    public function testZeroRolloutConditionsNoMatchDecisionRuleIdNull(): void
    {
        $core = new ABCore($this->buildStorage([
            'GATE' => [
                new Rule('gate-rule', 'rule-zero', '', 0.0, [
                    new Condition('PROPS', 'country', 'ANY_OF_CASE_SENSITIVE', ['CN']),
                ], null),
            ],
        ]));

        // User has no 'country' property, so condition fails -> matched=false
        $result = $core->evaluate(new User('', 'user1'), 'test_gate', ABCore::TYPE_GATE);

        self::assertFalse($result->checkFeatureGate());
        self::assertNull($result->decisionRuleId);
    }

    /**
     * No matched gate rule -> decisionRuleId == null.
     */
    public function testNoMatchedGateRuleDecisionRuleIdNull(): void
    {
        $core = new ABCore($this->buildStorage([
            'GATE' => [
                new Rule('gate-rule', 'rule-nomatch', '', 100.0, [
                    new Condition('PROPS', 'country', 'ANY_OF_CASE_SENSITIVE', ['CN']),
                ], null),
            ],
        ]));

        $result = $core->evaluate(new User('', 'user1'), 'test_gate', ABCore::TYPE_GATE);

        self::assertFalse($result->checkFeatureGate());
        self::assertNull($result->decisionRuleId);
    }

    /**
     * First-match-wins: first rule matches (zero rollout) -> immediately returns, second rule never evaluated.
     */
    public function testFirstMatchWinsZeroRolloutStopsAtFirstRule(): void
    {
        $core = new ABCore($this->buildStorage([
            'GATE' => [
                new Rule('gate-rule-1', 'rule-blocked', '', 0.0, [
                    new Condition('COMMON', 'public', 'IS_TRUE', null),
                ], null),
                new Rule('gate-rule-2', 'rule-pass', '', 100.0, [
                    new Condition('COMMON', 'public', 'IS_TRUE', null),
                ], null),
            ],
        ]));

        $result = $core->evaluate(new User('', 'user1'), 'test_gate', ABCore::TYPE_GATE);

        // First rule matched (IS_TRUE) with rollout=0 → first-match-wins → gate fails.
        self::assertFalse($result->checkFeatureGate());
        self::assertSame('rule-blocked', $result->decisionRuleId);
    }

    /**
     * First-match-wins: first rule matches (rollout rejects) -> immediately returns.
     */
    public function testFirstMatchWinsRolloutRejectionStopsAtFirstRule(): void
    {
        $core = new ABCore($this->buildStorage([
            'GATE' => [
                new Rule('gate-rule-1', 'rule-rejected', 'salt-reject', 0.01, [
                    new Condition('COMMON', 'public', 'IS_TRUE', null),
                ], null),
                new Rule('gate-rule-2', 'rule-pass', '', 100.0, [
                    new Condition('COMMON', 'public', 'IS_TRUE', null),
                ], null),
            ],
        ]));

        $result = $core->evaluate(new User('', 'user-miss'), 'test_gate', ABCore::TYPE_GATE);

        // First rule matched (IS_TRUE) with rollout=0.01 → almost certainly fails →
        // first-match-wins → gate fails, decisionRuleId is first rule.
        self::assertFalse($result->checkFeatureGate());
        self::assertSame('rule-rejected', $result->decisionRuleId);
    }

    /**
     * First-match-wins: first rule conditions don't match -> skip to second rule which passes.
     */
    public function testUnmatchedFirstRuleSkipsToSecondRule(): void
    {
        $core = new ABCore($this->buildStorage([
            'GATE' => [
                new Rule('gate-rule-vip', 'rule-vip', '', 100.0, [
                    new Condition('PROPS', 'country', 'ANY_OF_CASE_SENSITIVE', ['CN']),
                ], null),
                new Rule('gate-rule-public', 'rule-public', '', 100.0, [
                    new Condition('COMMON', 'public', 'IS_TRUE', null),
                ], null),
            ],
        ]));

        // User has no 'country' property → first rule unmatched → skip to second rule
        $result = $core->evaluate(new User('', 'user1'), 'test_gate', ABCore::TYPE_GATE);

        self::assertTrue($result->checkFeatureGate());
        self::assertSame('rule-public', $result->decisionRuleId);
    }

    /**
     * Traffic rejection -> decisionRuleId == traffic rule id.
     */
    public function testTrafficRejectionSetsDecisionRuleId(): void
    {
        $core = new ABCore($this->buildStorage([
            'TRAFFIC' => [
                new Rule('traffic-rule', 'traffic-r1', 'salt-traffic', 0.0, [
                    new Condition('COMMON', 'public', 'IS_TRUE', null),
                ], 'fail'),
            ],
            'GATE' => [
                new Rule('gate-rule', 'gate-r1', '', 100.0, [
                    new Condition('COMMON', 'public', 'IS_TRUE', null),
                ], null),
            ],
        ]));

        $result = $core->evaluate(new User('', 'user1'), 'test_gate', ABCore::TYPE_GATE);

        // Traffic rule: conditions match, rollout=0 => matched=true, pass=false => !pass=true => rejected
        self::assertSame('fail', $result->variantId);
        self::assertSame('traffic-r1', $result->decisionRuleId);
    }

    /**
     * Sticky cache hit -> decisionRuleId == null (cache path returns early without rule evaluation).
     */
    public function testStickyCacheHitDecisionRuleIdNull(): void
    {
        $handler = new MemoryStickyHandler();
        $handler->data['10-user-cached'] = json_encode(['v' => 'pass'], JSON_THROW_ON_ERROR);

        $core = new ABCore(new Storage(
            1,
            new ABEnv(),
            [
                'sticky_gate' => new ABSpec(
                    10,
                    'sticky_gate',
                    'Sticky Gate',
                    ABCore::TYPE_GATE,
                    '',
                    'LOGIN_ID',
                    true,
                    true, // sticky=true
                    '',
                    1,
                    false,
                    [
                        'GATE' => [
                            new Rule('gate-rule', 'rule-sticky', '', 100.0, [
                                new Condition('COMMON', 'public', 'IS_TRUE', null),
                            ], null),
                        ],
                    ],
                    []
                ),
            ]
        ), $handler);

        $result = $core->evaluate(new User('', 'user-cached'), 'sticky_gate', ABCore::TYPE_GATE);

        self::assertTrue($result->checkFeatureGate());
        // Sticky cache hit returns early; no rule evaluation occurs, so decisionRuleId remains null.
        self::assertNull($result->decisionRuleId);
    }

    /**
     * @param array<string, list<Rule>> $rules
     */
    private function buildStorage(array $rules): Storage
    {
        return new Storage(
            1,
            new ABEnv(),
            [
                'test_gate' => new ABSpec(
                    1,
                    'test_gate',
                    'Test Gate',
                    ABCore::TYPE_GATE,
                    '',
                    'LOGIN_ID',
                    true,
                    false,
                    '',
                    1,
                    false,
                    $rules,
                    []
                ),
            ]
        );
    }
}
