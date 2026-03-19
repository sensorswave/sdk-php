<?php

declare(strict_types=1);

namespace SensorsWave\Tests\ABTesting;

use PHPUnit\Framework\TestCase;
use InvalidArgumentException;
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

    public function testClientCanEvaluateAllABResultsAndTrackImpressions(): void
    {
        $transport = new FakeTransport();
        $client = Client::create(
            'https://collector.example.com',
            'test-token',
            new Config(
                transport: $transport,
                ab: new ABConfig(
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

        $resultMap = [];
        foreach ($results as $result) {
            $resultMap[$result->key] = $result;
        }

        self::assertTrue($resultMap['Gate_A']->checkFeatureGate());
        self::assertTrue($resultMap['Gate_B']->checkFeatureGate());
        self::assertFalse($resultMap['Gate_C']->checkFeatureGate());

        self::assertCount(3, $transport->requests);

        $payloads = array_map(
            static fn (Request $request): array => json_decode($request->body, true, 512, JSON_THROW_ON_ERROR),
            $transport->requests
        );
        self::assertSame('$FeatureImpress', $payloads[0][0]['event']);
        self::assertSame('$FeatureImpress', $payloads[1][0]['event']);
        self::assertSame('$FeatureImpress', $payloads[2][0]['event']);
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

    public function testClientCanInitializeFromRemoteMetaWithoutSpecs(): void
    {
        $transport = new class implements TransportInterface {
            /** @var list<Request> */
            public array $requests = [];

            public function send(Request $request): Response
            {
                $this->requests[] = $request;

                if ($request->method === 'GET') {
                    return new Response(
                        200,
                        json_encode([
                            'code' => 0,
                            'data' => [
                                'update' => true,
                                'update_time' => 11,
                                'ab_env' => [],
                                'ab_specs' => [],
                            ],
                        ], JSON_THROW_ON_ERROR)
                    );
                }

                return new Response(200, '{}');
            }
        };

        $client = Client::create(
            'http://example.com',
            'test-token',
            new Config(
                transport: $transport,
                ab: new ABConfig(projectSecret: 'secret')
            )
        );

        self::assertFalse($client->checkFeatureGate(new User('', 'user-pass'), 'missing_key'));
        self::assertCount(1, $transport->requests);
        self::assertSame('GET', $transport->requests[0]->method);
    }

    public function testClientLeavesABCoreUninitializedWhenRemoteMetaFails(): void
    {
        $transport = new class implements TransportInterface {
            public function send(Request $request): Response
            {
                return new Response(500, '{"msg":"fail"}');
            }
        };

        $client = Client::create(
            'http://example.com',
            'test-token',
            new Config(
                transport: $transport,
                ab: new ABConfig(projectSecret: 'secret')
            )
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ab core not initialized');
        $client->checkFeatureGate(new User('', 'user-pass'), 'TestSpec');
    }

    public function testClientLogsEachInitializationFailureWhenRemoteMetaFails(): void
    {
        $transport = new class implements TransportInterface {
            public function send(Request $request): Response
            {
                return new Response(500, '{"msg":"fail"}');
            }
        };
        $logger = new class implements \SensorsWave\Contract\LoggerInterface {
            /** @var list<string> */
            public array $errors = [];

            public function debug(string $message, mixed ...$context): void
            {
            }

            public function info(string $message, mixed ...$context): void
            {
            }

            public function warn(string $message, mixed ...$context): void
            {
            }

            public function error(string $message, mixed ...$context): void
            {
                $this->errors[] = $message;
            }
        };

        $client = Client::create(
            'http://example.com',
            'test-token',
            new Config(
                logger: $logger,
                transport: $transport,
                ab: new ABConfig(projectSecret: 'secret')
            )
        );

        try {
            $client->checkFeatureGate(new User('', 'user-pass'), 'TestSpec');
            self::fail('client should fail when ab core is not initialized');
        } catch (InvalidArgumentException) {
            self::assertCount(2, $logger->errors);
            self::assertStringContainsString('ab meta refresh failed', $logger->errors[0]);
            self::assertStringContainsString('ab meta refresh failed', $logger->errors[1]);
        }
    }

    public function testClientRefreshesRemoteMetaOnNextEvaluationAfterInterval(): void
    {
        $firstPayload = json_encode([
            'code' => 0,
            'data' => [
                'update' => true,
                'update_time' => 1,
                'ab_specs' => [],
            ],
        ], JSON_THROW_ON_ERROR);
        $secondPayload = file_get_contents(dirname(__DIR__) . '/Fixtures/ab/gate/public.json') ?: '';

        $transport = new class ($firstPayload, $secondPayload) implements TransportInterface {
            /** @var list<Request> */
            public array $requests = [];
            private int $getCalls = 0;

            public function __construct(
                private readonly string $firstPayload,
                private readonly string $secondPayload,
            ) {
            }

            public function send(Request $request): Response
            {
                $this->requests[] = $request;

                if ($request->method === 'GET') {
                    $this->getCalls++;
                    return new Response(200, $this->getCalls === 1 ? $this->firstPayload : $this->secondPayload);
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
                    metaLoadIntervalMs: 10,
                )
            )
        );

        self::assertFalse($client->checkFeatureGate(new User('', 'user-pass'), 'TestSpec'));
        usleep(20_000);
        self::assertTrue($client->checkFeatureGate(new User('', 'user-pass'), 'TestSpec'));

        $getRequests = array_values(array_filter(
            $transport->requests,
            static fn (Request $request): bool => $request->method === 'GET'
        ));
        self::assertCount(2, $getRequests);
    }

    public function testClientKeepsExistingStorageWhenRefreshSaysNoUpdate(): void
    {
        $initialPayload = file_get_contents(dirname(__DIR__) . '/Fixtures/ab/gate/public.json') ?: '';
        $secondPayload = json_encode([
            'code' => 0,
            'data' => [
                'update' => false,
                'update_time' => 1763391230637,
                'ab_specs' => [],
            ],
        ], JSON_THROW_ON_ERROR);

        $transport = new class ($initialPayload, $secondPayload) implements TransportInterface {
            /** @var list<Request> */
            public array $requests = [];
            private int $getCalls = 0;

            public function __construct(
                private readonly string $initialPayload,
                private readonly string $secondPayload,
            ) {
            }

            public function send(Request $request): Response
            {
                $this->requests[] = $request;

                if ($request->method === 'GET') {
                    $this->getCalls++;
                    return new Response(200, $this->getCalls === 1 ? $this->initialPayload : $this->secondPayload);
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
                    metaLoadIntervalMs: 10,
                )
            )
        );

        self::assertTrue($client->checkFeatureGate(new User('', 'user-pass'), 'TestSpec'));
        usleep(20_000);
        self::assertTrue($client->checkFeatureGate(new User('', 'user-pass'), 'TestSpec'));

        $getRequests = array_values(array_filter(
            $transport->requests,
            static fn (Request $request): bool => $request->method === 'GET'
        ));
        self::assertCount(2, $getRequests);
    }

    public function testClientLogsRefreshFailureAndKeepsExistingStorage(): void
    {
        $initialPayload = file_get_contents(dirname(__DIR__) . '/Fixtures/ab/gate/public.json') ?: '';

        $transport = new class ($initialPayload) implements TransportInterface {
            /** @var list<Request> */
            public array $requests = [];
            private int $getCalls = 0;

            public function __construct(private readonly string $initialPayload)
            {
            }

            public function send(Request $request): Response
            {
                $this->requests[] = $request;

                if ($request->method === 'GET') {
                    $this->getCalls++;
                    if ($this->getCalls === 1) {
                        return new Response(200, $this->initialPayload);
                    }

                    return new Response(500, '{"msg":"refresh-fail"}');
                }

                return new Response(200, '{}');
            }
        };
        $logger = new class implements \SensorsWave\Contract\LoggerInterface {
            /** @var list<string> */
            public array $errors = [];

            public function debug(string $message, mixed ...$context): void
            {
            }

            public function info(string $message, mixed ...$context): void
            {
            }

            public function warn(string $message, mixed ...$context): void
            {
            }

            public function error(string $message, mixed ...$context): void
            {
                $this->errors[] = $message;
            }
        };

        $client = Client::create(
            'http://example.com',
            'test-token',
            new Config(
                logger: $logger,
                transport: $transport,
                ab: new ABConfig(
                    projectSecret: 'secret',
                    metaLoadIntervalMs: 10,
                )
            )
        );

        self::assertTrue($client->checkFeatureGate(new User('', 'user-pass'), 'TestSpec'));
        usleep(20_000);
        self::assertTrue($client->checkFeatureGate(new User('', 'user-pass'), 'TestSpec'));
        self::assertCount(1, $logger->errors);
        self::assertStringContainsString('ab meta refresh failed', $logger->errors[0]);
    }
}
