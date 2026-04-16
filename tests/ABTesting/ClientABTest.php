<?php

declare(strict_types=1);

namespace SensorsWave\Tests\ABTesting;

use PHPUnit\Framework\TestCase;
use SensorsWave\Client\Client;
use SensorsWave\Config\ABConfig;
use SensorsWave\Config\Config;
use SensorsWave\Model\User;
use SensorsWave\Tests\Support\FakeTransport;
use SensorsWave\Tests\Support\MemoryABSpecStore;
use SensorsWave\Tests\Support\MemoryEventQueue;

final class ClientABTest extends TestCase
{
    public function testClientCanEvaluateGateConfigAndExperimentFromPreloadedSpecs(): void
    {
        $queue = new MemoryEventQueue();
        $client = Client::create(
            'https://collector.example.com',
            'test-token',
            new Config(
                eventQueue: $queue,
                ab: new ABConfig(
                    abSpecStore: new MemoryABSpecStore(),
                    loadABSpecs: file_get_contents(dirname(__DIR__) . '/Fixtures/ab/gate/public.json') ?: ''
                )
            )
        );

        self::assertTrue($client->checkFeatureGate(new User('', 'user-pass'), 'TestSpec'));
        $client->close();

        $messages = $queue->dequeue(50);
        self::assertNotSame([], $messages);
        self::assertSame('$FeatureImpress', json_decode($messages[0]->payload, true)['event']);
    }

    public function testClientCanEvaluateAllABResultsAndTrackImpressions(): void
    {
        $queue = new MemoryEventQueue();
        $client = Client::create(
            'https://collector.example.com',
            'test-token',
            new Config(
                eventQueue: $queue,
                ab: new ABConfig(
                    abSpecStore: new MemoryABSpecStore(),
                    loadABSpecs: file_get_contents(dirname(__DIR__) . '/Fixtures/ab/gate/multi_gates.json') ?: ''
                )
            )
        );

        $results = $client->evaluateAll(new User(
            '',
            'user5',
            \SensorsWave\Model\Properties::create()
                ->set('$app_version', '10.5')
                ->set('$country', 'JP')
        ));

        self::assertCount(3, $results);
        $client->close();

        $messages = $queue->dequeue(50);
        self::assertNotSame([], $messages);
        self::assertCount(3, $messages);
    }

    public function testClientEvaluatesFromLocalStoreWithoutRemoteRequests(): void
    {
        $snapshot = file_get_contents(dirname(__DIR__) . '/Fixtures/ab/gate/public.json') ?: '';
        $store = new MemoryABSpecStore($snapshot);
        $transport = new FakeTransport();
        $client = Client::create(
            'https://collector.example.com',
            'test-token',
            new Config(
                transport: $transport,
                eventQueue: new MemoryEventQueue(),
                ab: new ABConfig(
                    projectSecret: 'secret',
                    abSpecStore: $store,
                )
            )
        );

        self::assertTrue($client->checkFeatureGate(new User('', 'user-pass'), 'TestSpec'));
        self::assertCount(0, $transport->requests);
    }

    public function testClientFailsClosedWhenLocalSnapshotIsMissing(): void
    {
        $client = Client::create(
            'https://collector.example.com',
            'test-token',
            new Config(
                eventQueue: new MemoryEventQueue(),
                ab: new ABConfig(
                    abSpecStore: new MemoryABSpecStore()
                )
            )
        );

        self::assertFalse($client->checkFeatureGate(new User('', 'user-pass'), 'TestSpec'));
        self::assertNull($client->getFeatureConfig(new User('', 'user-pass'), 'TestSpec')->variantId);
        self::assertNull($client->getExperiment(new User('', 'user-pass'), 'TestSpec')->variantId);
    }

    public function testClientUsesSnapshotRegardlessOfAge(): void
    {
        $snapshot = file_get_contents(dirname(__DIR__) . '/Fixtures/ab/gate/public.json') ?: '';
        $store = new MemoryABSpecStore($snapshot);
        $client = Client::create(
            'https://collector.example.com',
            'test-token',
            new Config(
                eventQueue: new MemoryEventQueue(),
                ab: new ABConfig(
                    abSpecStore: $store,
                )
            )
        );

        self::assertTrue($client->checkFeatureGate(new User('', 'user-pass'), 'TestSpec'));
    }

    public function testClientCanExportABSpecsSnapshotFromStore(): void
    {
        $snapshot = file_get_contents(dirname(__DIR__) . '/Fixtures/ab/gate/public.json') ?: '';
        $store = new MemoryABSpecStore($snapshot);
        $client = Client::create(
            'https://collector.example.com',
            'test-token',
            new Config(
                eventQueue: new MemoryEventQueue(),
                ab: new ABConfig(
                    abSpecStore: $store
                )
            )
        );

        $exported = $client->getABSpecs();
        $payload = json_decode($exported, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(0, $payload['code'] ?? null);
        self::assertArrayHasKey('ab_specs', $payload['data'] ?? []);
    }

    public function testExp008ClientCanEvaluateAllABResultsAndTrackImpressions(): void
    {
        $this->assertNotFalse(strpos($this->name(), 'Exp008'));
        $this->testClientCanEvaluateAllABResultsAndTrackImpressions();
    }

    public function testExp009ClientFailsClosedWhenLocalSnapshotIsMissing(): void
    {
        $this->assertNotFalse(strpos($this->name(), 'Exp009'));
        $this->testClientFailsClosedWhenLocalSnapshotIsMissing();
    }

    public function testExp010MissingExperimentKeyReturnsEmptyResult(): void
    {
        $queue = new MemoryEventQueue();
        $client = Client::create(
            'https://collector.example.com',
            'test-token',
            new Config(
                eventQueue: $queue,
                ab: new ABConfig(
                    abSpecStore: new MemoryABSpecStore(),
                    loadABSpecs: file_get_contents(dirname(__DIR__) . '/Fixtures/ab/exp/public.json') ?: ''
                )
            )
        );

        $result = $client->getExperiment(new User('', 'user-pass'), 'missing-experiment');

        $this->assertNull($result->variantId);
        $client->close();
    }
}
