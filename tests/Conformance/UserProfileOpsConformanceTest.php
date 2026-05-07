<?php

declare(strict_types=1);

namespace SensorsWave\Tests\Conformance;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SensorsWave\Model\Event;
use SensorsWave\Model\Properties;
use SensorsWave\Model\UserPropertyOptions;
use SensorsWave\Tracking\Predefined;

/**
 * UserProfileOpsConformanceTest — A 类完全派生测试。
 *
 * 输入与期望值都来自 tests/Fixtures/conformance/{fixtures,golden}/user-profile-ops.json
 * （由 backend-sdk-harness 通过 scripts/sync_ab_testdata.py --conformance-data 同步）。
 * 不在本测试代码硬编码任何 spec 字面量或 expected 值。
 *
 * case 覆盖 5 种 profile 操作（profile_append / profile_union / profile_increment /
 * profile_unset / profile_delete），按 operation 调对应 UserPropertyOptions API 构造
 * $UserSet 事件，normalize 后与 golden 比较。
 *
 * 与 conformance/runners/php/user_profile_ops.py 对齐：
 * `injected` 模式下从两边比较前 substitute `$lib`="php"（golden 由 Go 参考实现生成
 * `$lib`="go"）+ 删除 `$lib_version`（运行时变量）。
 *
 * 详见 docs/specs/testing-strategy.md 第 4.1 / 5 节。
 */
final class UserProfileOpsConformanceTest extends TestCase
{
    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: array<string, mixed>}>
     */
    public static function caseProvider(): iterable
    {
        $loaded = ConformanceLoader::load('user-profile-ops');
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
        $options = UserPropertyOptions::create();
        $type = '';

        switch ($operation) {
            case 'profile_append':
                /** @var array<string, mixed> $listProps */
                $listProps = (array) ($case['list_properties'] ?? []);
                foreach ($listProps as $key => $values) {
                    $options->append((string) $key, $values);
                }
                $type = Predefined::USER_SET_TYPE_APPEND;
                break;

            case 'profile_union':
                /** @var array<string, mixed> $listProps */
                $listProps = (array) ($case['list_properties'] ?? []);
                foreach ($listProps as $key => $values) {
                    $options->union((string) $key, $values);
                }
                $type = Predefined::USER_SET_TYPE_UNION;
                break;

            case 'profile_increment':
                /** @var array<string, mixed> $props */
                $props = (array) ($case['properties'] ?? []);
                foreach ($props as $key => $value) {
                    if (is_int($value) || is_float($value)) {
                        $options->increment((string) $key, $value);
                    }
                }
                $type = Predefined::USER_SET_TYPE_INCREMENT;
                break;

            case 'profile_unset':
                /** @var list<mixed> $keys */
                $keys = (array) ($case['property_keys'] ?? []);
                foreach ($keys as $key) {
                    $options->unset((string) $key);
                }
                $type = Predefined::USER_SET_TYPE_UNSET;
                break;

            case 'profile_delete':
                $options->delete();
                $type = Predefined::USER_SET_TYPE_DELETE;
                break;

            default:
                throw new \RuntimeException("unknown operation: $operation");
        }

        return Event::create($anonId, $loginId, Predefined::EVENT_USER_SET)
            ->withUserPropertyOptions($options)
            ->withProperties(Properties::create()->set(Predefined::USER_SET_TYPE, $type));
    }

}
