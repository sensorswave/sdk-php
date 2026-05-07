<?php

declare(strict_types=1);

namespace SensorsWave\Tests\Conformance;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SensorsWave\Signing\RequestSigner;

/**
 * RequestSigningACS3ConformanceTest — A 类完全派生测试。
 *
 * 输入与期望值都来自 tests/Fixtures/conformance/{fixtures,golden}/request-signing-acs3.json
 * （由 backend-sdk-harness 通过 scripts/sync_ab_testdata.py --conformance-data 同步）。
 * 不在本测试代码里硬编码任何 spec 字面量或 expected 值。
 *
 * 详见 docs/specs/testing-strategy.md 第 4.1 / 5 节。
 */
final class RequestSigningACS3ConformanceTest extends TestCase
{
    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: array<string, mixed>}>
     */
    public static function caseProvider(): iterable
    {
        $loaded = ConformanceLoader::load('request-signing-acs3');
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
        // 复制 headers，避免污染 fixture 解析结果
        /** @var array<string, string> $headers */
        $headers = [];
        foreach ((array) ($case['headers'] ?? []) as $k => $v) {
            $headers[(string) $k] = (string) $v;
        }

        $authorization = RequestSigner::sign(
            (string) $case['method'],
            (string) $case['uri'],
            (string) $case['query_string'],
            $headers,
            (string) $case['body'],
            (string) $case['source_token'],
            (string) $case['project_secret'],
        );

        self::assertSame(
            $expected['authorization'],
            $authorization,
            'authorization should match golden'
        );

        // 校验 sign 是否按预期回填了 headers（含大小写敏感的 Authorization 字段）
        /** @var array<string, string> $expectedHeaders */
        $expectedHeaders = (array) ($expected['headers'] ?? []);
        foreach ($expectedHeaders as $key => $val) {
            self::assertSame(
                (string) $val,
                $headers[(string) $key] ?? '',
                "header '$key' should match golden"
            );
        }
    }
}
