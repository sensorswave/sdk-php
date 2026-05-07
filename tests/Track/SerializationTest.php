<?php

declare(strict_types=1);

namespace SensorsWave\Tests\Track;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use SensorsWave\Model\Event;
use SensorsWave\Model\Properties;
use SensorsWave\Model\User;
use SensorsWave\Tracking\EventSerializer;
use SensorsWave\Tracking\UserPropertyEventFactory;

/**
 * SerializationTest — 保留以下两类：
 *   - track-005（unit_test 类）：JSON 序列化往返关键字段保留。
 *   - property-datetime-iso8601-utc capability 范围的时间归一化测试（不属 tracking-core）。
 *
 * tracking-core 的 A 类方法（identify / basic-track-event / profile-set 默认属性等）
 * 已迁移到 tests/Conformance/TrackingCoreTest（完全派生模式）。
 */
final class SerializationTest extends TestCase
{
    /**
     * track-005 — JSON 序列化往返：事件经 EventSerializer::serialize 后属性完整保留。
     * source: track_test.go#TestEventJSONSerialization（命名变体匹配此方法）
     */
    public function testTrack005EventSerializerProducesExpectedJsonShape(): void
    {
        $event = Event::create('anon-123', 'user-456', 'TestEvent')
            ->withProperties(Properties::create()->set('test_key', 'test_value'));
        $event->normalize();

        $json = EventSerializer::serialize($event);
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('anon-123', $decoded['anon_id']);
        self::assertSame('user-456', $decoded['login_id']);
        self::assertSame('TestEvent', $decoded['event']);
        self::assertSame('php', $decoded['properties']['$lib']);
        self::assertSame(\SensorsWave\Support\SDKInfo::VERSION, $decoded['properties']['$lib_version']);
        self::assertSame('test_value', $decoded['properties']['test_key']);
    }

    public function testEventSerializerUsesIso8601UtcFormatForNativePropertyDateTime(): void
    {
        $event = Event::create('anon-123', 'user-456', 'TimeProbe')
            ->withTime(1776932130123)
            ->withProperties(
                Properties::create()
                    ->set('native_time', new DateTimeImmutable('2026-04-23T08:15:30.123Z'))
                    ->set('literal_time', '2026-04-23 08:15:30.123')
            );
        $event->normalize();

        $decoded = json_decode(EventSerializer::serialize($event), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(1776932130123, $decoded['time']);
        self::assertSame('2026-04-23T08:15:30.123Z', $decoded['properties']['native_time']);
        self::assertSame('2026-04-23 08:15:30.123', $decoded['properties']['literal_time']);
    }

    public function testProfileSetSerializationUsesIso8601UtcFormatForNativePropertyDateTime(): void
    {
        $event = UserPropertyEventFactory::profileSet(
            new User('', 'user-456'),
            Properties::create()
                ->set('registered_at', new DateTimeImmutable('2026-04-23T08:15:30.123Z'))
                ->set('literal_time', '2026-04-23 08:15:30.123')
        )->withTime(1776932130123);

        $event->normalize();
        $decoded = json_decode(EventSerializer::serialize($event), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('2026-04-23T08:15:30.123Z', $decoded['user_properties']['$set']['registered_at']);
        self::assertSame('2026-04-23 08:15:30.123', $decoded['user_properties']['$set']['literal_time']);
    }
}
