<?php

declare(strict_types=1);

namespace SensorsWave\Conformance;

use PHPUnit\Framework\TestCase;

/**
 * 数据驱动的 conformance 测试 runner。
 * 实现 8 层防漏机制 + $lib/$lib_version 处理。
 *
 * 本文件为只读资产，由 harness 仓库维护，verify_sdk.py 校验 hash。
 *
 * 最后更新：2026-03-25
 * 执行者：AI
 */
class ConformanceRunner
{
    private string $testdataDir;

    public function __construct(string $testdataDir = 'testdata/conformance')
    {
        $this->testdataDir = $testdataDir;
    }

    /**
     * 运行指定 capability 的 conformance 测试。
     *
     * @param TestCase    $test         PHPUnit 测试实例（用于断言）
     * @param string      $capabilityId capability 名称
     * @param CaseAdapter $adapter      capability 的适配器实现
     */
    public function run(TestCase $test, string $capabilityId, CaseAdapter $adapter): void
    {
        $fixture = $this->loadJson('fixtures', $capabilityId);
        $golden = $this->loadJson('golden', $capabilityId);

        // 防漏 1：capability_id 交叉校验
        $test->assertSame(
            $fixture['capability_id'],
            $golden['capability_id'],
            'fixture/golden capability_id mismatch'
        );

        $cases = $fixture['cases'];
        $goldenCases = $golden['cases'];

        // 防漏 2：case id 集合完全相等
        $fixtureIds = $this->extractIds($cases);
        $goldenIds = $this->extractIds($goldenCases);
        sort($fixtureIds);
        sort($goldenIds);
        $test->assertSame($fixtureIds, $goldenIds, 'case id sets differ');

        // 防漏 3：case id 唯一性
        $test->assertCount(
            count(array_unique($fixtureIds)),
            $cases,
            'duplicate fixture case ids'
        );
        $test->assertCount(
            count(array_unique($goldenIds)),
            $goldenCases,
            'duplicate golden case ids'
        );

        $goldenById = $this->indexById($goldenCases);
        $executed = 0;

        foreach ($cases as $caseObj) {
            $caseId = $caseObj['id'];
            $test->assertArrayHasKey($caseId, $goldenById, "no golden case for: {$caseId}");

            $goldenCase = $goldenById[$caseId];
            $expected = $goldenCase['expected'] ?? null;

            // 防漏 4：golden 中必须有对应 expected
            $test->assertNotNull($expected, "missing expected for: {$caseId}");

            // 调用 adapter
            $actual = $adapter->execute($caseObj);

            // 防漏 5：actual 不能为 null
            $test->assertNotNull($actual, "adapter returned null for: {$caseId}");

            // 处理 $lib/$lib_version（lib_metadata_mode）
            $libMode = $caseObj['lib_metadata_mode'] ?? null;
            if ($libMode === 'injected') {
                $expected = $this->replaceLibMetadata($expected, $adapter->libName(), $adapter->libVersion());
            }

            // 防漏 6：deep-equal 比较
            $test->assertEquals(
                $this->normalizeJson($expected),
                $this->normalizeJson($actual),
                "mismatch for case: {$caseId}"
            );

            $executed++;
        }

        // 防漏 7：执行计数 == fixture case 数
        $test->assertSame(count($cases), $executed, 'not all cases executed');

        // 防漏 8：manifest 总数校验（如有 case_count 元数据）
        if (isset($fixture['case_count'])) {
            $test->assertSame(
                $fixture['case_count'],
                count($cases),
                'manifest case_count mismatch'
            );
        }
    }

    /**
     * 运行负例自检：篡改 golden 后框架必须 fail。
     */
    public function runSelfCheck(TestCase $test, string $capabilityId, CaseAdapter $adapter): void
    {
        $golden = $this->loadJson('golden', $capabilityId);
        if (empty($golden['cases'])) {
            $test->markTestSkipped('no golden cases to self-check');
            return;
        }

        // 篡改第一个 case 的 expected（显式处理 object 和 array 两种类型）
        $expected = $golden['cases'][0]['expected'];
        if (is_array($expected) && !array_is_list($expected)) {
            // Object 类型：添加篡改字段
            $golden['cases'][0]['expected']['__selfcheck_tampered__'] = 'this-should-cause-failure';
        } else {
            // Array 或其他类型：替换为一定不匹配的 object
            $golden['cases'][0]['expected'] = ['__selfcheck_tampered__' => 'this-should-cause-failure'];
        }

        // 写入临时目录
        $tmpDir = sys_get_temp_dir() . '/conformance-selfcheck-' . uniqid();
        mkdir($tmpDir . '/fixtures', 0755, true);
        mkdir($tmpDir . '/golden', 0755, true);

        // 复制 fixture
        copy(
            $this->testdataDir . '/fixtures/' . $capabilityId . '.json',
            $tmpDir . '/fixtures/' . $capabilityId . '.json'
        );

        // 写入篡改后的 golden
        file_put_contents(
            $tmpDir . '/golden/' . $capabilityId . '.json',
            json_encode($golden, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );

        // 运行 conformance，期望 fail
        $tamperedRunner = new self($tmpDir);
        $failed = false;
        try {
            $tamperedRunner->run($test, $capabilityId, $adapter);
        } catch (\Throwable $e) {
            $failed = true;
        }

        // 清理
        $this->recursiveDelete($tmpDir);

        $test->assertTrue(
            $failed,
            'SELFCHECK FAILURE: tampered golden passed — framework may be compromised'
        );
    }

    // ============================================================
    // 内部辅助方法
    // ============================================================

    /**
     * @return array<string, mixed>
     */
    private function loadJson(string $subdir, string $capabilityId): array
    {
        $path = $this->testdataDir . '/' . $subdir . '/' . $capabilityId . '.json';
        $content = file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException("Failed to load: {$path}");
        }
        return json_decode($content, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<int, array<string, mixed>> $cases
     * @return array<int, string>
     */
    private function extractIds(array $cases): array
    {
        return array_map(fn(array $case) => $case['id'], $cases);
    }

    /**
     * @param array<int, array<string, mixed>> $cases
     * @return array<string, array<string, mixed>>
     */
    private function indexById(array $cases): array
    {
        $result = [];
        foreach ($cases as $case) {
            $result[$case['id']] = $case;
        }
        return $result;
    }

    /**
     * 替换 expected 中的 $lib/$lib_version。支持 object (assoc array) 和 array (indexed array)。
     *
     * @param array<string, mixed>|list<mixed> $expected
     * @return array<string, mixed>|list<mixed>
     */
    private function replaceLibMetadata(array $expected, string $libName, string $libVersion): array
    {
        // Deep copy
        $copy = json_decode(json_encode($expected), true);

        // 判断是 object (assoc) 还是 list (indexed)
        if (array_is_list($copy)) {
            // 数组类型：对每个 object 元素递归处理
            foreach ($copy as $i => $item) {
                if (is_array($item) && !array_is_list($item)) {
                    $copy[$i] = $this->replaceLibMetadata($item, $libName, $libVersion);
                }
            }
            return $copy;
        }

        // Object 类型
        if (isset($copy['properties']['$lib'])) {
            $copy['properties']['$lib'] = $libName;
        }
        if (isset($copy['properties']['$lib_version'])) {
            $copy['properties']['$lib_version'] = $libVersion;
        }
        return $copy;
    }

    /**
     * 通过 JSON 序列化规范化后比较，消除类型差异。
     *
     * @param mixed $data
     * @return mixed
     */
    private function normalizeJson(mixed $data): mixed
    {
        return json_decode(json_encode($data), true);
    }

    private function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getRealPath());
            } else {
                unlink($item->getRealPath());
            }
        }
        rmdir($dir);
    }
}
