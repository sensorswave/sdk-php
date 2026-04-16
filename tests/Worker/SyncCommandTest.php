<?php

declare(strict_types=1);

namespace SensorsWave\Tests\Worker;

use PHPUnit\Framework\TestCase;
use SensorsWave\Config\ABConfig;
use SensorsWave\Http\Request;
use SensorsWave\Http\Response;
use SensorsWave\Http\TransportInterface;
use SensorsWave\Tests\Support\MemoryABSpecStore;
use SensorsWave\Worker\SyncCommand;

final class SyncCommandTest extends TestCase
{
    public function testSyncCommandFetchesRemoteSnapshotAndSavesIt(): void
    {
        $snapshot = file_get_contents(dirname(__DIR__) . '/Fixtures/ab/gate/public.json') ?: '';
        $store = new MemoryABSpecStore();
        $transport = new class ($snapshot) implements TransportInterface {
            /** @var list<Request> */
            public array $requests = [];

            public function __construct(private readonly string $snapshot)
            {
            }

            public function send(Request $request): Response
            {
                $this->requests[] = $request;
                return new Response(200, $this->snapshot);
            }
        };

        $command = new SyncCommand(
            'https://collector.example.com',
            'test-token',
            new ABConfig(
                projectSecret: 'secret',
                abSpecStore: $store,
            ),
            $transport
        );

        self::assertSame(0, $command->run());
        $storedSnapshot = $store->load();
        self::assertIsString($storedSnapshot);
        $decoded = json_decode($storedSnapshot, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(0, $decoded['code'] ?? null);
        self::assertArrayHasKey('ab_specs', $decoded['data'] ?? []);
        self::assertCount(1, $transport->requests);
    }

    public function testSyncCommandKeepsExistingSnapshotWhenRemoteRequestFails(): void
    {
        $existing = '{"code":0,"data":{"update":true,"update_time":1,"ab_specs":[]}}';
        $store = new MemoryABSpecStore($existing);
        $transport = new class implements TransportInterface {
            public function send(Request $request): Response
            {
                return new Response(500, '{"msg":"fail"}');
            }
        };

        $command = new SyncCommand(
            'https://collector.example.com',
            'test-token',
            new ABConfig(
                projectSecret: 'secret',
                abSpecStore: $store,
            ),
            $transport
        );

        self::assertSame(1, $command->run());
        self::assertSame($existing, $store->load());
    }

    public function testMeta006SyncCommandFetchesRemoteSnapshotAndSavesIt(): void
    {
        // refresh mock
        $this->assertNotFalse(strpos($this->name(), 'Meta006'));
        $this->testSyncCommandFetchesRemoteSnapshotAndSavesIt();
    }

    public function testMeta009SyncCommandSkipsStoreUpdateWhenRemoteUpdateIsFalse(): void
    {
        // update false
        $existing = '{"code":0,"data":{"update":true,"update_time":1,"ab_specs":[]}}';
        $store = new MemoryABSpecStore($existing);
        $transport = new class implements TransportInterface {
            public function send(Request $request): Response
            {
                return new Response(200, '{"code":0,"data":{"update":false,"update_time":2,"ab_specs":[]}}');
            }
        };

        $command = new SyncCommand(
            'https://collector.example.com',
            'test-token',
            new ABConfig(
                projectSecret: 'secret',
                abSpecStore: $store,
            ),
            $transport
        );

        $this->assertSame(0, $command->run());
        $this->assertSame($existing, $store->load());
    }

    public function testMeta011SyncCommandAcceptsEmptySpecList(): void
    {
        // empty
        $store = new MemoryABSpecStore();
        $transport = new class implements TransportInterface {
            public function send(Request $request): Response
            {
                return new Response(200, '{"code":0,"data":{"update":true,"update_time":2,"ab_specs":[]}}');
            }
        };

        $command = new SyncCommand(
            'https://collector.example.com',
            'test-token',
            new ABConfig(
                projectSecret: 'secret',
                abSpecStore: $store,
            ),
            $transport
        );

        $this->assertSame(0, $command->run());
        $this->assertIsString($store->load());
        $payload = json_decode($store->load(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(0, $payload['code'] ?? null);
        $this->assertSame([], $payload['data']['ab_specs'] ?? null);
        $this->assertSame(2, $payload['data']['update_time'] ?? null);
    }
}
