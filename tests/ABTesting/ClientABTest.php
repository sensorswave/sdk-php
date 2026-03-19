<?php

declare(strict_types=1);

namespace SensorsWave\Tests\ABTesting;

use PHPUnit\Framework\TestCase;
use SensorsWave\Client\Client;
use SensorsWave\Config\ABConfig;
use SensorsWave\Config\Config;
use SensorsWave\Http\Request;
use SensorsWave\Http\Response;
use SensorsWave\Http\TransportInterface;
use SensorsWave\Model\User;
use SensorsWave\Tests\Support\FakeTransport;

final class ClientABTest extends TestCase
{
    public function testClientCanEvaluateGateConfigAndExperimentFromPreloadedSpecs(): void
    {
        $transport = new FakeTransport();
        $client = Client::create(
            'https://collector.example.com',
            'test-token',
            new Config(
                transport: $transport,
                ab: new ABConfig(
                    loadABSpecs: file_get_contents(dirname(__DIR__) . '/Fixtures/ab/gate/public.json') ?: ''
                )
            )
        );

        $passed = $client->checkFeatureGate(new User('', 'user-pass'), 'TestSpec');
        self::assertTrue($passed);
        self::assertCount(1, $transport->requests);
    }

    public function testClientCanEvaluateConfigAndExperiment(): void
    {
        $transport = new FakeTransport();
        $configClient = Client::create(
            'https://collector.example.com',
            'test-token',
            new Config(
                transport: $transport,
                ab: new ABConfig(
                    loadABSpecs: file_get_contents(dirname(__DIR__) . '/Fixtures/ab/config/public.json') ?: ''
                )
            )
        );
        $configResult = $configClient->getFeatureConfig(new User('', 'config-public-user-1'), 'bMHsfOAUKx');
        self::assertNotNull($configResult->variantId);
        self::assertContains($configResult->getString('color', ''), ['blue', 'red', 'orange']);

        $experimentClient = Client::create(
            'https://collector.example.com',
            'test-token',
            new Config(
                transport: $transport,
                ab: new ABConfig(
                    loadABSpecs: file_get_contents(dirname(__DIR__) . '/Fixtures/ab/exp/public.json') ?: ''
                )
            )
        );
        $experimentResult = $experimentClient->getExperiment(new User('', 'user0'), 'New_Experiment');
        self::assertContains($experimentResult->variantId, ['v1', 'v2', null]);
    }

    public function testClientCanLoadRemoteMetaWithCustomEndpointAndPath(): void
    {
        $metaBody = file_get_contents(dirname(__DIR__) . '/Fixtures/ab/gate/public.json') ?: '';
        $transport = new class ($metaBody) implements TransportInterface {
            /** @var list<Request> */
            public array $requests = [];

            public function __construct(private readonly string $metaBody)
            {
            }

            public function send(Request $request): Response
            {
                $this->requests[] = $request;

                if ($request->method === 'GET') {
                    return new Response(200, $this->metaBody);
                }

                return new Response(200, '{}');
            }
        };

        $client = Client::create(
            'https://collector.example.com',
            'test-token',
            new Config(
                transport: $transport,
                ab: new ABConfig(
                    projectSecret: 'secret',
                    metaEndpoint: 'http://example.com',
                    metaUriPath: '/custom/path',
                )
            )
        );

        $passed = $client->checkFeatureGate(new User('', 'user-pass'), 'TestSpec');

        self::assertTrue($passed);
        self::assertSame('GET', $transport->requests[0]->method);
        self::assertSame('http://example.com/custom/path', $transport->requests[0]->url);
        self::assertSame('test-token', $transport->requests[0]->headers['SourceToken'] ?? null);
        self::assertSame('php', $transport->requests[0]->headers['X-SDK'] ?? null);
    }

    public function testClientUsesMainEndpointWhenMetaEndpointEmpty(): void
    {
        $metaBody = file_get_contents(dirname(__DIR__) . '/Fixtures/ab/gate/public.json') ?: '';
        $transport = new class ($metaBody) implements TransportInterface {
            /** @var list<Request> */
            public array $requests = [];

            public function __construct(private readonly string $metaBody)
            {
            }

            public function send(Request $request): Response
            {
                $this->requests[] = $request;

                if ($request->method === 'GET') {
                    return new Response(200, $this->metaBody);
                }

                return new Response(200, '{}');
            }
        };

        $client = Client::create(
            'http://example.com',
            'test-token',
            new Config(
                transport: $transport,
                ab: new ABConfig(
                    projectSecret: 'secret',
                    metaUriPath: '/ab/all4eval',
                )
            )
        );

        $passed = $client->checkFeatureGate(new User('', 'user-pass'), 'TestSpec');

        self::assertTrue($passed);
        self::assertSame('http://example.com/ab/all4eval', $transport->requests[0]->url);
    }

    public function testClientUsesGoDefaultMetaPathWhenPathOmitted(): void
    {
        $metaBody = file_get_contents(dirname(__DIR__) . '/Fixtures/ab/gate/public.json') ?: '';
        $transport = new class ($metaBody) implements TransportInterface {
            /** @var list<Request> */
            public array $requests = [];

            public function __construct(private readonly string $metaBody)
            {
            }

            public function send(Request $request): Response
            {
                $this->requests[] = $request;

                if ($request->method === 'GET') {
                    return new Response(200, $this->metaBody);
                }

                return new Response(200, '{}');
            }
        };

        $client = Client::create(
            'http://example.com',
            'test-token',
            new Config(
                transport: $transport,
                ab: new ABConfig(
                    projectSecret: 'secret',
                )
            )
        );

        $passed = $client->checkFeatureGate(new User('', 'user-pass'), 'TestSpec');

        self::assertTrue($passed);
        self::assertSame('http://example.com/ab/all4eval', $transport->requests[0]->url);
    }

    public function testClientCanExportAndReuseABSpecsSnapshot(): void
    {
        $transport = new FakeTransport();
        $originalClient = Client::create(
            'https://collector.example.com',
            'test-token',
            new Config(
                transport: $transport,
                ab: new ABConfig(
                    loadABSpecs: file_get_contents(dirname(__DIR__) . '/Fixtures/ab/gate/public.json') ?: ''
                )
            )
        );

        $snapshot = $originalClient->getABSpecs();
        $snapshotPayload = json_decode($snapshot, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(0, $snapshotPayload['code'] ?? null);
        self::assertArrayHasKey('ab_specs', $snapshotPayload['data'] ?? []);

        $warmTransport = new FakeTransport();
        $warmClient = Client::create(
            'https://collector.example.com',
            'test-token',
            new Config(
                transport: $warmTransport,
                ab: new ABConfig(loadABSpecs: $snapshot)
            )
        );

        self::assertTrue($warmClient->checkFeatureGate(new User('', 'user-pass'), 'TestSpec'));
    }
}
