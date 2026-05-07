<?php

declare(strict_types=1);

namespace SensorsWave\Tests\Track;

use PHPUnit\Framework\TestCase;
use SensorsWave\Exception\EmptyUserIdsException;
use SensorsWave\Exception\EventNameEmptyException;
use SensorsWave\Model\Event;
use SensorsWave\Model\Properties;
use SensorsWave\Model\User;
use SensorsWave\Model\UserPropertyOptions;

final class ModelTest extends TestCase
{
    public function testEventNormalizeRequiresAtLeastOneUserId(): void
    {
        $event = Event::create('', '', 'TestEvent');

        $this->expectException(EmptyUserIdsException::class);
        $event->normalize();
    }

    public function testEventNormalizeRequiresNonEmptyEventName(): void
    {
        $event = Event::create('anon-123', 'user-456', '');

        $this->expectException(EventNameEmptyException::class);
        $event->normalize();
    }

    public function testUserPropertyOptionsSupportMutationHelpers(): void
    {
        $options = UserPropertyOptions::create()
            ->set('name', 'alice')
            ->setOnce('created_at', '2026-03-19 12:00:00')
            ->increment('score', 2)
            ->append('tags', 'vip')
            ->append('tags', ['beta'])
            ->union('roles', 'admin')
            ->union('roles', ['admin', 'owner'])
            ->unset('legacy_field')
            ->delete();

        self::assertSame('alice', $options->group('$set')['name']);
        self::assertSame('2026-03-19 12:00:00', $options->group('$set_once')['created_at']);
        self::assertSame(2, $options->group('$increment')['score']);
        self::assertSame(['vip', 'beta'], $options->group('$append')['tags']);
        // $union setter is append-only; dedupe happens in Event::normalize()
        // based on final ISO8601 UTC strings.
        self::assertSame(['admin', 'admin', 'owner'], $options->group('$union')['roles']);
        self::assertArrayHasKey('legacy_field', $options->group('$unset'));
        self::assertTrue($options->isDeleteSet());
    }

    public function testUserWithAbUserPropertyCopiesOriginalMap(): void
    {
        $user = (new User('anon-123', 'user-456'))
            ->withAbUserProperty('region', 'cn');

        $mutated = $user->withAbUserProperty('plan', 'pro');

        self::assertSame(['region' => 'cn'], $user->abUserProperties()->all());
        self::assertSame(['region' => 'cn', 'plan' => 'pro'], $mutated->abUserProperties()->all());
    }

    public function testUserSupportsPlainPhpArraysForAbUserProperties(): void
    {
        $user = new User('anon-123', 'user-456', ['region' => 'cn']);
        $mutated = $user->withAbUserProperties(['plan' => 'pro']);

        self::assertSame(['region' => 'cn'], $user->abUserProperties()->all());
        self::assertSame(
            ['region' => 'cn', 'plan' => 'pro'],
            $mutated->abUserProperties()->all()
        );
    }
}
