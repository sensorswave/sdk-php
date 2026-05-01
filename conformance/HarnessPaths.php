<?php

declare(strict_types=1);

namespace SensorsWave\Conformance;

/**
 * 解析 backend-sdk-harness 仓库路径。
 *
 * 解析顺序：
 *   1. 环境变量 BACKEND_SDK_HARNESS_ROOT（绝对路径）
 *   2. 从 cwd 向上 walk，查找含 backend-sdk-harness/conformance/fixtures 的目录
 *
 * 未找到时抛 RuntimeException，错误信息提示如何修复。
 *
 * 本文件为只读资产，由 harness 仓库维护，verify_sdk.py 校验 hash。
 *
 * 最后更新：2026-05-01
 * 执行者：AI
 */
final class HarnessPaths
{
    private const ENV_VAR = 'BACKEND_SDK_HARNESS_ROOT';
    private const HARNESS_DIR = 'backend-sdk-harness';
    private const MARKER = 'conformance/fixtures';
    private const MAX_WALK_UP = 6;

    /**
     * 返回 harness 的 conformance 目录路径（含 fixtures/ 和 golden/ 子目录）。
     */
    public static function conformanceRoot(): string
    {
        return self::harnessRoot() . DIRECTORY_SEPARATOR . 'conformance';
    }

    /**
     * 返回 harness 仓库根目录。
     */
    public static function harnessRoot(): string
    {
        $env = getenv(self::ENV_VAR);
        if ($env !== false && $env !== '') {
            $marker = $env . DIRECTORY_SEPARATOR . self::MARKER;
            if (is_dir($marker)) {
                return $env;
            }
            throw new \RuntimeException(sprintf(
                '%s=%s but %s does not exist or is not a directory.',
                self::ENV_VAR,
                $env,
                $marker
            ));
        }

        $current = getcwd();
        if ($current === false) {
            throw new \RuntimeException('getcwd() failed; cannot resolve harness path');
        }

        for ($i = 0; $i < self::MAX_WALK_UP; $i++) {
            $candidate = $current . DIRECTORY_SEPARATOR . self::HARNESS_DIR;
            if (is_dir($candidate . DIRECTORY_SEPARATOR . self::MARKER)) {
                return $candidate;
            }
            $parent = dirname($current);
            if ($parent === $current) {
                break;
            }
            $current = $parent;
        }

        throw new \RuntimeException(sprintf(
            'Could not locate backend-sdk-harness. Searched %d ancestors of cwd. ' .
            'Set %s to the absolute path, or clone backend-sdk-harness as a sibling of this SDK.',
            self::MAX_WALK_UP,
            self::ENV_VAR
        ));
    }
}
