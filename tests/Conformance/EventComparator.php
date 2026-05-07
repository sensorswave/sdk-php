<?php

declare(strict_types=1);

namespace SensorsWave\Tests\Conformance;

/**
 * 派生测试中跨 capability 共享的 event JSON 比较辅助。
 *
 * 与 conformance/runners/php/*.py 中的 *_comparator / _normalize_expected 行为对齐：
 *   - normalizeEventOutput：删除空 anon_id / login_id / user_properties
 *   - substituteLibForPhp：injected 模式下把 expected 的 `$lib`="go" 替换为 "php"
 *     （golden 由 sdk-go 参考实现生成 `$lib`="go"）
 *   - stripLibVersion：删除 `$lib_version`（运行时变量，值随 SDK 版本演化）
 */
final class EventComparator
{
    /**
     * @param array<string, mixed> $output
     * @return array<string, mixed>
     */
    public static function normalizeEventOutput(array $output): array
    {
        if (($output['anon_id'] ?? '') === '') {
            unset($output['anon_id']);
        }
        if (($output['login_id'] ?? '') === '') {
            unset($output['login_id']);
        }
        if (($output['user_properties'] ?? []) === []) {
            unset($output['user_properties']);
        }
        return $output;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function stripLibVersion(array &$data): void
    {
        if (isset($data['properties']) && is_array($data['properties'])) {
            unset($data['properties']['$lib_version']);
        }
    }

    /**
     * @param array<string, mixed> $expected
     */
    public static function substituteLibForPhp(array &$expected): void
    {
        if (isset($expected['properties']) && is_array($expected['properties'])
            && isset($expected['properties']['$lib'])) {
            $expected['properties']['$lib'] = 'php';
        }
    }

    /**
     * 当 lib_metadata_mode==='injected' 时：actual 删 `$lib_version`，expected 替换 `$lib`
     * 并删 `$lib_version`。其他模式不动。
     *
     * @param array<string, mixed> $actual
     * @param array<string, mixed> $expected
     */
    public static function applyInjectedLibSubstitutions(?string $libMode, array &$actual, array &$expected): void
    {
        if ($libMode !== 'injected') {
            return;
        }
        self::substituteLibForPhp($expected);
        self::stripLibVersion($actual);
        self::stripLibVersion($expected);
    }
}
