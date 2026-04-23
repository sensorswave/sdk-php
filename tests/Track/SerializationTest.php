<?php

declare(strict_types=1);

namespace SensorsWave\Tests\Track;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use SensorsWave\Model\Event;
use SensorsWave\Model\Properties;
use SensorsWave\Model\User;
use SensorsWave\Tracking\EventSerializer;
use SensorsWave\Tracking\Predefined;
use SensorsWave\Tracking\UserPropertyEventFactory;

final class SerializationTest extends TestCase
{
    public function testIdentifyEventKeepsExpectedEventName(): void
    {
        $event = Event::create('anon-123', 'user-456', Predefined::EVENT_IDENTIFY);

        $event->normalize();

        self::assertSame('$Identify', $event->event());
    }

    public function testProfileSetEventContainsDefaultPropertiesAndPayload(): void
    {
        $event = UserPropertyEventFactory::profileSet(
            new User('anon-123', 'user-456'),
            Properties::create()->set('plan', 'pro')
        );

        $event->normalize();

        self::assertSame('$UserSet', $event->event());
        self::assertSame('user_set', $event->properties()->get('$user_set_type'));
        self::assertSame('pro', $event->userProperties()->group('$set')['plan']);
        self::assertSame('php', $event->properties()->get('$lib'));
        self::assertSame(\SensorsWave\Support\SDKInfo::VERSION, $event->properties()->get('$lib_version'));
    }

    public function testEventSerializerProducesExpectedJsonShape(): void
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

    public function testTrack005EventSerializerProducesExpectedJsonShape(): void
    {
        // test_value TestEvent
        $this->assertNotFalse(strpos($this->name(), 'Track005'));
        $this->testEventSerializerProducesExpectedJsonShape();
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
