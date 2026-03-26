<?php

declare(strict_types=1);

namespace SensorsWave\Conformance;

use PHPUnit\Framework\TestCase;

/**
 * Conformance 测试基类，封装 PHPUnit 集成。
 * 每个 capability 的测试类继承此基类。
 *
 * 示例：
 *   class TrackingCoreConformanceTest extends ConformanceTestCase {
 *       protected function capabilityId(): string { return 'tracking-core'; }
 *       protected function createAdapter(): CaseAdapter { return new TrackingCoreAdapter(); }
 *   }
 *
 * 本文件为只读资产，由 harness 仓库维护，verify_sdk.py 校验 hash。
 *
 * 最后更新：2026-03-25
 * 执行者：AI
 */
abstract class ConformanceTestCase extends TestCase
{
    private const DEFAULT_TESTDATA_DIR = 'testdata/conformance';

    /**
     * 返回 capability ID（如 "tracking-core"）。
     */
    abstract protected function capabilityId(): string;

    /**
     * 创建 capability 的 Adapter 实例。
     */
    abstract protected function createAdapter(): CaseAdapter;

    /**
     * 返回 testdata 目录路径。子类可覆盖。
     */
    protected function testdataDir(): string
    {
        return self::DEFAULT_TESTDATA_DIR;
    }

    /**
     * PHPUnit 测试入口：运行 conformance 全部 case。
     */
    public function testConformance(): void
    {
        $runner = new ConformanceRunner($this->testdataDir());
        $runner->run($this, $this->capabilityId(), $this->createAdapter());
    }

    /**
     * PHPUnit 测试入口：运行负例自检。
     */
    public function testSelfCheck(): void
    {
        $runner = new ConformanceRunner($this->testdataDir());
        $runner->runSelfCheck($this, $this->capabilityId(), $this->createAdapter());
    }
}
