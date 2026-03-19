<?php

declare(strict_types=1);

namespace SensorsWave\Tests\Track;

use PHPUnit\Framework\TestCase;
use SensorsWave\Client\Client;
use SensorsWave\Config\Config;
use SensorsWave\Exception\EmptyUserIdsException;
use SensorsWave\Exception\IdentifyRequiresBothIdsException;
use SensorsWave\Model\Properties;
use SensorsWave\Model\User;
use SensorsWave\Tests\Support\FakeTransport;

final class ClientTrackingTest extends TestCase
{
    public function testIdentifySendsExpectedPayload(): void
    {
        $transport = new FakeTransport();
        $client = Client::create(
            'https://collector.example.com/path',
            'test-token',
            new Config(transport: $transport)
        );

        $client->identify(new User('anon-123', 'user-456'));

        self::assertCount(1, $transport->requests);
        self::assertSame('POST', $transport->requests[0]->method);
        self::assertSame('https://collector.example.com/in/track', $transport->requests[0]->url);
        self::assertSame('test-token', $transport->requests[0]->headers['SourceToken'] ?? null);

        $payload = json_decode($transport->requests[0]->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(1, $payload);
        self::assertSame('$Identify', $payload[0]['event']);
        self::assertSame('anon-123', $payload[0]['anon_id']);
        self::assertSame('user-456', $payload[0]['login_id']);
    }

    public function testIdentifyRequiresBothIds(): void
    {
        $client = Client::create(
            'https://collector.example.com',
            'test-token',
            new Config(transport: new FakeTransport())
        );

        $this->expectException(IdentifyRequiresBothIdsException::class);
        $client->identify(new User('', 'user-456'));
    }

    public function testTrackEventSendsCustomProperties(): void
    {
        $transport = new FakeTransport();
        $client = Client::create(
            'https://collector.example.com',
            'test-token',
            new Config(transport: $transport)
        );

        $client->trackEvent(
            new User('anon-123', 'user-456'),
            'PageView',
            Properties::create()->set('page_name', '/home')
        );

        $payload = json_decode($transport->requests[0]->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('PageView', $payload[0]['event']);
        self::assertSame('/home', $payload[0]['properties']['page_name']);
        self::assertSame('php', $payload[0]['properties']['$lib']);
    }

    public function testTrackingApisAcceptPlainPhpArrays(): void
    {
        $transport = new FakeTransport();
        $client = Client::create(
            'https://collector.example.com',
            'test-token',
            new Config(transport: $transport)
        );

        $user = new User('anon-123', 'user-456');

        $client->trackEvent($user, 'ArrayEvent', ['page_name' => '/pricing']);
        $client->profileSet($user, ['plan' => 'pro']);
        $client->profileSetOnce($user, ['first_plan' => 'starter']);
        $client->profileIncrement($user, ['coins' => 3]);
        $client->profileAppend($user, ['tags' => ['php', 'sdk']]);
        $client->profileUnion($user, ['groups' => ['beta', 'beta', 'internal']]);

        self::assertCount(6, $transport->requests);

        $trackPayload = json_decode($transport->requests[0]->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('ArrayEvent', $trackPayload[0]['event']);
        self::assertSame('/pricing', $trackPayload[0]['properties']['page_name']);

        $setPayload = json_decode($transport->requests[1]->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('pro', $setPayload[0]['user_properties']['$set']['plan']);

        $setOncePayload = json_decode($transport->requests[2]->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('starter', $setOncePayload[0]['user_properties']['$set_once']['first_plan']);

        $incrementPayload = json_decode($transport->requests[3]->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(3, $incrementPayload[0]['user_properties']['$increment']['coins']);

        $appendPayload = json_decode($transport->requests[4]->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['php', 'sdk'], $appendPayload[0]['user_properties']['$append']['tags']);

        $unionPayload = json_decode($transport->requests[5]->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['beta', 'internal'], $unionPayload[0]['user_properties']['$union']['groups']);
    }

    public function testProfileSetSendsUserPropertyPayload(): void
    {
        $transport = new FakeTransport();
        $client = Client::create(
            'https://collector.example.com',
            'test-token',
            new Config(transport: $transport)
        );

        $client->profileSet(
            new User('anon-123', 'user-456'),
            Properties::create()->set('plan', 'pro')
        );

        $payload = json_decode($transport->requests[0]->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('$UserSet', $payload[0]['event']);
        self::assertSame('user_set', $payload[0]['properties']['$user_set_type']);
        self::assertSame('pro', $payload[0]['user_properties']['$set']['plan']);
    }

    public function testTrackEventRequiresAtLeastOneUserId(): void
    {
        $client = Client::create(
            'https://collector.example.com',
            'test-token',
            new Config(transport: new FakeTransport())
        );

        $this->expectException(EmptyUserIdsException::class);
        $client->trackEvent(new User('', ''), 'PageView', Properties::create());
    }
}
