<?php

declare(strict_types=1);

namespace SensorsWave\Tests\Conformance;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SensorsWave\Model\Event;
use SensorsWave\Model\ListProperties;
use SensorsWave\Model\Properties;
use SensorsWave\Model\User;
use SensorsWave\Model\UserPropertyOptions;
use SensorsWave\Tracking\Predefined;
use SensorsWave\Tracking\UserPropertyEventFactory;

/**
 * PropertyDatetimeIso8601UtcTest — A 类完全派生测试。
 *
 * 输入与期望值都来自
 * tests/Fixtures/conformance/{fixtures,golden}/property-datetime-iso8601-utc.json
 * 不在本测试代码硬编码任何 spec 字面量或 expected 值。
 *
 * fixture 字符串语义为 UTC 墙上时间；测试代码（与 conformance adapter 一致）把
 * `native_time_properties` / `native_time_list_properties` 列出的 key 解析为
 * \DateTimeImmutable（UTC），交给 normalize 输出 ISO8601。其他字符串保持原样。
 *
 * 与 conformance/runners/php/property_datetime_iso8601_utc.py 对齐：`injected` 模式下
 * 从两边比较前 substitute `$lib`="php" + 删除 `$lib_version`。
 */
final class PropertyDatetimeIso8601UtcTest extends TestCase
{
    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: array<string, mixed>}>
     */
    public static function caseProvider(): iterable
    {
        $loaded = ConformanceLoader::load('property-datetime-iso8601-utc');
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
        /** @var array<string, list<mixed>> $rawListProps */
        $rawListProps = (array) ($case['list_properties'] ?? []);
        /** @var list<string> $nativeKeys */
        $nativeKeys = (array) ($case['native_time_properties'] ?? []);
        /** @var list<string> $nativeListKeys */
        $nativeListKeys = (array) ($case['native_time_list_properties'] ?? []);

        switch ($operation) {
            case 'track_event':
                return Event::create($anonId, $loginId, (string) ($case['event'] ?? ''))
                    ->withProperties(self::nativeProperties($rawProps, $nativeKeys));

            case 'profile_set':
                return UserPropertyEventFactory::profileSet(
                    new User($anonId, $loginId),
                    self::nativeProperties($rawProps, $nativeKeys)
                );

            case 'profile_set_once': {
                $options = UserPropertyOptions::create();
                foreach (self::nativeProperties($rawProps, $nativeKeys)->all() as $key => $value) {
                    $options->setOnce((string) $key, $value);
                }
                return Event::create($anonId, $loginId, Predefined::EVENT_USER_SET)
                    ->withUserPropertyOptions($options);
            }

            case 'profile_append': {
                $options = UserPropertyOptions::create();
                foreach (self::nativeListProperties($rawListProps, $nativeListKeys)->all() as $key => $value) {
                    $options->append((string) $key, $value);
                }
                return Event::create($anonId, $loginId, Predefined::EVENT_USER_SET)
                    ->withUserPropertyOptions($options);
            }

            case 'profile_union': {
                $options = UserPropertyOptions::create();
                foreach (self::nativeListProperties($rawListProps, $nativeListKeys)->all() as $key => $value) {
                    $options->union((string) $key, $value);
                }
                return Event::create($anonId, $loginId, Predefined::EVENT_USER_SET)
                    ->withUserPropertyOptions($options);
            }

            default:
                throw new \RuntimeException("unknown operation: $operation");
        }
    }

    /**
     * @param array<string, mixed> $values
     * @param list<string> $nativeKeys
     */
    private static function nativeProperties(array $values, array $nativeKeys): Properties
    {
        $nativeMap = array_fill_keys($nativeKeys, true);
        $properties = Properties::create();
        foreach ($values as $key => $value) {
            if (isset($nativeMap[$key])) {
                $value = \DateTimeImmutable::createFromFormat(
                    'Y-m-d H:i:s.v',
                    (string) $value,
                    new \DateTimeZone('UTC')
                );
                if (!$value instanceof \DateTimeImmutable) {
                    throw new \RuntimeException("failed to parse native UTC property time: $key");
                }
            }
            $properties->set((string) $key, $value);
        }
        return $properties;
    }

    /**
     * @param array<string, list<mixed>> $values
     * @param list<string> $nativeKeys
     */
    private static function nativeListProperties(array $values, array $nativeKeys): ListProperties
    {
        $nativeMap = array_fill_keys($nativeKeys, true);
        $properties = ListProperties::create();
        foreach ($values as $key => $items) {
            if (isset($nativeMap[$key])) {
                $items = array_map(
                    static function (mixed $item): \DateTimeImmutable {
                        $parsed = \DateTimeImmutable::createFromFormat(
                            'Y-m-d H:i:s.v',
                            (string) $item,
                            new \DateTimeZone('UTC')
                        );
                        if (!$parsed instanceof \DateTimeImmutable) {
                            throw new \RuntimeException('failed to parse native UTC list time');
                        }
                        return $parsed;
                    },
                    $items
                );
            }
            $properties->set((string) $key, $items);
        }
        return $properties;
    }

}
