<?php

declare(strict_types=1);

namespace SensorsWave\Tests\Conformance;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SensorsWave\ABTesting\ABCore;
use SensorsWave\ABTesting\Storage;
use SensorsWave\ABTesting\StorageFactory;

/**
 * ABMetaSnapshotBootstrapTest — A 类完全派生测试。
 *
 * 输入与期望值都来自 tests/Fixtures/conformance/{fixtures,golden}/ab-meta-snapshot-bootstrap.json
 * + spec_file 间接引用 tests/testdata/{config,gate}/ 下的 spec JSON。
 *
 * 派生测试在每个 case 内通过 StorageFactory::fromJson 公开 API 加载 spec，
 * 调 ABCore::getABSpecs 导出快照（含 fromJson 回环 + normalize），
 * 再与 golden 比较。
 *
 * 执行逻辑与 conformance/adapters/php/ab_meta_snapshot_bootstrap.php 保持同义。
 */
final class ABMetaSnapshotBootstrapTest extends TestCase
{
    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: array<string, mixed>}>
     */
    public static function caseProvider(): iterable
    {
        $loaded = ConformanceLoader::load('ab-meta-snapshot-bootstrap');
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
        if (!self::caseAppliesTo($case, 'php')) {
            $this->markTestSkipped('case does not apply to php');
        }

        $specPath = self::testdataDir() . '/' . (string) $case['spec_file'];
        $raw = file_get_contents($specPath);
        self::assertNotFalse($raw, "read spec file: $specPath");

        $storage = StorageFactory::fromJson($raw);
        $core = new ABCore($storage);
        $snapshot = $core->getABSpecs();
        $roundtrip = StorageFactory::fromJson($snapshot);

        $actual = self::normalizeSnapshot(self::normalizeStorage($roundtrip));
        $expectedNormalized = self::normalizeSnapshot($expected);

        self::assertEquals($expectedNormalized, $actual, 'storage snapshot should match golden');
    }

    /**
     * 与 conformance/runners/php/ab_meta_snapshot_bootstrap.py 的 _normalize_snapshot 对齐：
     *   - 删除 override===null 的 key（spec 级别 override）
     *   - variant_payloads: [] → {} （PHP 空数组 ↔ Go 空 object）
     *   - rules 补齐 OVERRIDE/TRAFFIC/GATE/GROUP 默认；OVERRIDE: [] → null
     *
     * @param mixed $payload
     * @return mixed
     */
    private static function normalizeSnapshot(mixed $payload): mixed
    {
        if (is_array($payload) && self::isAssoc($payload)) {
            $normalized = [];
            foreach ($payload as $key => $value) {
                $normalized[$key] = self::normalizeSnapshot($value);
            }
            if (array_key_exists('override', $normalized) && $normalized['override'] === null) {
                unset($normalized['override']);
            }
            if (array_key_exists('variant_payloads', $normalized) && $normalized['variant_payloads'] === []) {
                $normalized['variant_payloads'] = new \stdClass();
            }
            if (isset($normalized['rules']) && is_array($normalized['rules'])) {
                $rules = &$normalized['rules'];
                foreach (['OVERRIDE', 'TRAFFIC', 'GATE', 'GROUP'] as $rkey) {
                    if (!array_key_exists($rkey, $rules)) {
                        $rules[$rkey] = $rkey === 'OVERRIDE' ? null : [];
                    }
                }
                if (($rules['OVERRIDE'] ?? null) === []) {
                    $rules['OVERRIDE'] = null;
                }
                unset($rules);
            }
            return $normalized;
        }
        if (is_array($payload)) {
            return array_map([self::class, 'normalizeSnapshot'], $payload);
        }
        return $payload;
    }

    /**
     * @param array<int|string, mixed> $arr
     */
    private static function isAssoc(array $arr): bool
    {
        if ($arr === []) {
            return false;
        }
        return array_keys($arr) !== range(0, count($arr) - 1);
    }

    private static function testdataDir(): string
    {
        return dirname(__DIR__) . '/testdata';
    }

    /**
     * @param array<string, mixed> $case
     */
    private static function caseAppliesTo(array $case, string $language): bool
    {
        if (!isset($case['languages']) || !is_array($case['languages'])) {
            return true;
        }

        return in_array($language, $case['languages'], true);
    }

    /**
     * @return array<string, mixed>
     */
    private static function normalizeStorage(Storage $storage): array
    {
        $specs = [];
        foreach ($storage->allSpecs() as $key => $spec) {
            $raw = $spec->toArray();
            $raw['rules'] = [
                'OVERRIDE' => array_key_exists('OVERRIDE', $raw['rules']) ? $raw['rules']['OVERRIDE'] : null,
                'TRAFFIC' => $raw['rules']['TRAFFIC'] ?? [],
                'GATE' => $raw['rules']['GATE'] ?? [],
                'GROUP' => $raw['rules']['GROUP'] ?? [],
            ];
            if ($raw['rules']['GROUP'] === []) {
                unset($raw['rules']['GROUP']);
            }
            if ($raw['rules']['TRAFFIC'] === []) {
                $raw['rules']['TRAFFIC'] = [];
            }
            $specs[$key] = $raw;
        }
        ksort($specs);

        return [
            'UpdateTime' => $storage->updateTime,
            'ABEnv' => $storage->abEnv->toArray(),
            'ABSpecs' => $specs,
        ];
    }
}
