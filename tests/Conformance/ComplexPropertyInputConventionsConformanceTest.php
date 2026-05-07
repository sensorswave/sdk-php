<?php

declare(strict_types=1);

namespace SensorsWave\Tests\Conformance;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SensorsWave\Model\Event;
use SensorsWave\Model\Properties;
use SensorsWave\Model\User;
use SensorsWave\Model\UserPropertyOptions;
use SensorsWave\Tracking\Predefined;
use SensorsWave\Tracking\UserPropertyEventFactory;

/**
 * ComplexPropertyInputConventionsConformanceTest — A 类完全派生测试。
 *
 * 输入与期望值都来自
 * tests/Fixtures/conformance/{fixtures,golden}/complex-property-input-conventions.json
 * （由 backend-sdk-harness 通过 scripts/sync_ab_testdata.py --conformance-data 同步）。
 * 不在本测试代码硬编码任何 spec 字面量或 expected 值。
 *
 * case 覆盖 5 种 operation（track_event / profile_set / profile_set_once /
 * profile_append / profile_union）下复杂属性 pass-through 行为：嵌套对象、对象数组、
 * 混合标量/对象的列表、空数组等。
 *
 * 与 conformance/runners/php/complex_property_input_conventions.py 对齐：
 * `injected` 模式下从两边比较前 substitute `$lib`="php" + 删除 `$lib_version`。
 *
 * 详见 docs/specs/testing-strategy.md 第 4.1 / 5 节。
 */
final class ComplexPropertyInputConventionsConformanceTest extends TestCase
{
    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: array<string, mixed>}>
     */
    public static function caseProvider(): iterable
    {
        $loaded = ConformanceLoader::load('complex-property-input-conventions');
        foreach ($loaded['cases'] as $case) {
            $id = (string) $case['id'];
            $expected = $loaded['expected'][$id] ?? null;
            self::assertIsArray($expected, "expected for $id not array");
            yield $id => [$case, $expected];
        }
    }

    /**
     * @param array<string, mixed> $case
     * @param array<string, mixed> $expected
     */
    #[DataProvider('caseProvider')]
    public function testCase(array $case, array $expected): void
    {
        $event = self::buildEventFromCase($case);
        $event = $event->withTime((int) $case['time'])->withTraceId((string) $case['trace_id']);
        $event->normalize();

        /** @var array<string, mixed> $actualRaw */
        $actualRaw = json_decode(json_encode($event, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        $actual = EventComparator::normalizeEventOutput($actualRaw);
        $expectedNormalized = $expected;
        EventComparator::applyInjectedLibSubstitutions(
            $case['lib_metadata_mode'] ?? null,
            $actual,
            $expectedNormalized
        );

        self::assertEquals($expectedNormalized, $actual, 'event JSON should match golden');
    }

    /**
     * @param array<string, mixed> $case
     */
    private static function buildEventFromCase(array $case): Event
    {
        $anonId = (string) ($case['anon_id'] ?? '');
        $loginId = (string) ($case['login_id'] ?? '');
        $operation = (string) $case['operation'];
        /** @var array<string, mixed> $rawProps */
        $rawProps = (array) ($case['properties'] ?? []);
        /** @var array<string, mixed> $rawListProps */
        $rawListProps = (array) ($case['list_properties'] ?? []);

        switch ($operation) {
            case 'track_event':
                return Event::create($anonId, $loginId, (string) ($case['event'] ?? ''))
                    ->withProperties(Properties::fromArray($rawProps));

            case 'profile_set':
                return UserPropertyEventFactory::profileSet(
                    new User($anonId, $loginId),
                    Properties::fromArray($rawProps)
                );

            case 'profile_set_once': {
                $options = UserPropertyOptions::create();
                foreach ($rawProps as $key => $value) {
                    $options->setOnce((string) $key, $value);
                }
                return Event::create($anonId, $loginId, Predefined::EVENT_USER_SET)
                    ->withUserPropertyOptions($options)
                    ->withProperties(Properties::create()->set(
                        Predefined::USER_SET_TYPE,
                        Predefined::USER_SET_TYPE_SET_ONCE
                    ));
            }

            case 'profile_append': {
                $options = UserPropertyOptions::create();
                foreach ($rawListProps as $key => $values) {
                    $options->append((string) $key, $values);
                }
                return Event::create($anonId, $loginId, Predefined::EVENT_USER_SET)
                    ->withUserPropertyOptions($options)
                    ->withProperties(Properties::create()->set(
                        Predefined::USER_SET_TYPE,
                        Predefined::USER_SET_TYPE_APPEND
                    ));
            }

            case 'profile_union': {
                $options = UserPropertyOptions::create();
                foreach ($rawListProps as $key => $values) {
                    $options->union((string) $key, $values);
                }
                return Event::create($anonId, $loginId, Predefined::EVENT_USER_SET)
                    ->withUserPropertyOptions($options)
                    ->withProperties(Properties::create()->set(
                        Predefined::USER_SET_TYPE,
                        Predefined::USER_SET_TYPE_UNION
                    ));
            }

            default:
                throw new \RuntimeException("unknown operation: $operation");
        }
    }

}
