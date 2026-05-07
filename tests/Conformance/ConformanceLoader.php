<?php

declare(strict_types=1);

namespace SensorsWave\Tests\Conformance;

use PHPUnit\Framework\Assert;

/**
 * 加载 conformance fixture 与 golden，返回 cases 数组与按 case id 索引的 expected。
 *
 * 路径硬编码为 tests/Fixtures/conformance/{fixtures,golden}/<capability>.json。
 *
 * 接口语义对齐 sdk-go 的 loadConformance。
 */
final class ConformanceLoader
{
    /** @var array<string, array{cases: list<array<string, mixed>>, expected: array<string, mixed>}> */
    private static array $cache = [];

    /**
     * @return array{cases: list<array<string, mixed>>, expected: array<string, mixed>}
     */
    public static function load(string $capability): array
    {
        if (isset(self::$cache[$capability])) {
            return self::$cache[$capability];
        }

        $base = dirname(__DIR__) . '/Fixtures/conformance';
        $fixturesPath = $base . '/fixtures/' . $capability . '.json';
        $goldenPath = $base . '/golden/' . $capability . '.json';

        $fixtureRaw = file_get_contents($fixturesPath);
        Assert::assertNotFalse($fixtureRaw, "read fixtures: $fixturesPath");
        /** @var mixed $fixture */
        $fixture = json_decode($fixtureRaw, true);
        Assert::assertIsArray($fixture, "unmarshal fixtures: $fixturesPath");
        Assert::assertSame(
            $capability,
            $fixture['capability_id'] ?? null,
            "fixture capability_id mismatch"
        );

        $cases = $fixture['cases'] ?? [];
        Assert::assertIsArray($cases, "fixture.cases not array");
        foreach ($cases as $i => $case) {
            Assert::assertIsArray($case, "case[$i] not array");
            Assert::assertArrayHasKey('id', $case, "case[$i] missing id");
            Assert::assertNotSame('', $case['id'], "case[$i] id empty");
        }

        $goldenRaw = file_get_contents($goldenPath);
        Assert::assertNotFalse($goldenRaw, "read golden: $goldenPath");
        /** @var mixed $golden */
        $golden = json_decode($goldenRaw, true);
        Assert::assertIsArray($golden, "unmarshal golden: $goldenPath");
        Assert::assertSame(
            $capability,
            $golden['capability_id'] ?? null,
            "golden capability_id mismatch"
        );

        /** @var array<string, mixed> $expectedById */
        $expectedById = [];
        foreach ($golden['cases'] ?? [] as $c) {
            Assert::assertIsArray($c, "golden case not array");
            Assert::assertArrayHasKey('id', $c, "golden case missing id");
            $expectedById[$c['id']] = $c['expected'] ?? null;
        }

        // 双向对齐：每个 fixture case 必须有 golden expected
        foreach ($cases as $case) {
            $id = $case['id'];
            Assert::assertArrayHasKey(
                $id,
                $expectedById,
                "fixture case '$id' has no matching golden expected"
            );
        }

        /** @var list<array<string, mixed>> $cases */
        $result = ['cases' => $cases, 'expected' => $expectedById];
        self::$cache[$capability] = $result;
        return $result;
    }
}
