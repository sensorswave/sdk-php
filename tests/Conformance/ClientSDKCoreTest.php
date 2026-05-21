<?php

declare(strict_types=1);

namespace SensorsWave\Tests\Conformance;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SensorsWave\Client\Client;
use SensorsWave\Config\Config;
use SensorsWave\Http\Request;
use SensorsWave\Model\User;
use SensorsWave\Tests\Support\FakeTransport;
use SensorsWave\Tests\Support\MemoryEventQueue;
use SensorsWave\Worker\SendCommand;

/**
 * ClientSDKCoreTest — A 类完全派生测试。
 *
 * 输入与期望值都来自 tests/Fixtures/conformance/{fixtures,golden}/client-sdk-core.json
 * 不在本测试代码硬编码任何 spec 字面量或 expected 值。
 *
 * client-sdk-core 在 conformance 中验证「client → endpoint 解析、close 触发 flush、batch
 * 形态」是输入到捕获请求的确定性映射，仍属 A 类。sdk-php 路径用 FakeTransport 注入
 * Config，无需 in-process server（与 sdk-go httptest 模式不同）。执行逻辑与
 * conformance/adapters/php/client_sdk_core.php 保持同义。
 */
final class ClientSDKCoreTest extends TestCase
{
    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: array<string, mixed>}>
     */
    public static function caseProvider(): iterable
    {
        $loaded = ConformanceLoader::load('client-sdk-core');
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
        $endpointSuffix = (string) ($case['endpoint_suffix'] ?? '');
        $trackUriPath = (string) ($case['track_uri_path'] ?? '');
        $endpoint = 'https://collector.example.com' . $endpointSuffix;

        $transport = new FakeTransport();
        $queue = new MemoryEventQueue();
        $config = new Config(
            trackUriPath: $trackUriPath,
            flushIntervalMs: 3_600_000,
            transport: $transport,
            eventQueue: $queue,
        );
        $client = Client::create($endpoint, 'test-token', $config);

        /** @var list<array<string, mixed>> $events */
        $events = (array) ($case['events'] ?? []);
        foreach ($events as $event) {
            $client->trackEvent(
                new User((string) ($event['anon_id'] ?? ''), (string) ($event['login_id'] ?? '')),
                (string) ($event['event'] ?? ''),
                []
            );
        }

        $preCloseRequestCount = count($transport->requests);
        $client->close();
        // Track 请求路径只写 eventQueue；transport 由独立 worker 进程驱动。
        // 派生测试在同进程内显式跑 SendCommand 把 queue 流到 transport。
        (new SendCommand($endpoint, 'test-token', $config, $transport))->run();

        $requests = [];
        foreach ($transport->requests as $request) {
            self::assertInstanceOf(Request::class, $request, 'transport entry must be Request');
            /** @var list<array<string, mixed>> $payloads */
            $payloads = json_decode($request->body, true, 512, JSON_THROW_ON_ERROR);
            $eventNames = [];
            foreach ($payloads as $payload) {
                $eventNames[] = (string) ($payload['event'] ?? '');
            }
            $requests[] = [
                'method' => $request->method,
                'path' => (string) parse_url($request->url, PHP_URL_PATH),
                'source_token' => $request->headers['SourceToken'] ?? null,
                'event_names' => $eventNames,
                'batch_size' => count($payloads),
            ];
        }

        $actual = [
            'pre_close_request_count' => $preCloseRequestCount,
            'request_count' => count($transport->requests),
            'requests' => $requests,
        ];

        self::assertEquals($expected, $actual, 'client capture should match golden');
    }
}
