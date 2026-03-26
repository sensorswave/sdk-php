<?php

declare(strict_types=1);

namespace SensorsWave\Conformance;

/**
 * Conformance case 适配器接口。
 * Agent 为每个 capability 实现此接口。
 *
 * 约束：
 * - 未知 operation 必须抛出 \RuntimeException，禁止吞掉
 * - Adapter 类不允许读取 golden 文件路径
 * - 不可返回 null
 *
 * 最后更新：2026-03-25
 * 执行者：AI
 */
interface CaseAdapter
{
    /**
     * 处理单个 conformance case。
     *
     * @param array<string, mixed> $caseInput fixture 中的单个 case 数据
     * @return array<string, mixed>|list<mixed> SDK 产生的输出（object 或 array），不可返回 null
     * @throws \RuntimeException 未知 operation 必须抛错
     */
    public function execute(array $caseInput): array;

    /**
     * 返回当前 SDK 的 $lib 标识（如 "php"）。
     */
    public function libName(): string;

    /**
     * 返回当前 SDK 的 $lib_version。
     */
    public function libVersion(): string;
}
