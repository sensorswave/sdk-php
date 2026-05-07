<?php

declare(strict_types=1);

namespace SensorsWave\Tests\Conformance;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SensorsWave\ABTesting\ABCore;
use SensorsWave\ABTesting\StorageFactory;
use SensorsWave\Contract\StickyHandlerInterface;
use SensorsWave\Model\User;

/**
 * ABCoreEvaluationConformanceTest — A 类完全派生测试。
 *
 * 输入与期望值都来自 tests/Fixtures/conformance/{fixtures,golden}/ab-core-evaluation.json
 * + spec_file 间接引用 tests/testdata/{config,gate,exp}/ 下的 spec JSON。
 *
 * 与 ab-meta-snapshot-bootstrap 同 spec_file 路径 A：派生测试在 case 内通过
 * StorageFactory::fromJson 公开 API 加载 spec → ABCore::evaluate 拿评估结果。
 *
 * eval_mode：single（单用户 → variant_id+check_gate+variant_params）/ distribution
 * （多用户 → 排序后的 variant_id 分布）。sticky_cache 通过 MemoryStickyHandler 注入。
 *
 * 与 conformance/runners/php/ab_core_evaluation.py 对齐：variant_params=null 时
 * actual==expected 容忍 actual 不带该 key（result_comparator 行为）。
 *
 * 详见 docs/specs/testing-strategy.md 第 4.1 / 5 节。
 */
final class ABCoreEvaluationConformanceTest extends TestCase
{
    /** @var array<string, string> spec_file → JSON 内容；ab-core fixture 116 cases 仅 49 unique */
    private static array $specCache = [];

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: mixed}>
     */
    public static function caseProvider(): iterable
    {
        $loaded = ConformanceLoader::load('ab-core-evaluation');
        foreach ($loaded['cases'] as $case) {
            $id = (string) $case['id'];
            $expected = $loaded['expected'][$id] ?? null;
            yield $id => [$case, $expected];
        }
    }

    /**
     * @param array<string, mixed> $case
     * @param mixed $expected
     */
    #[DataProvider('caseProvider')]
    public function testCase(array $case, mixed $expected): void
    {
        $specFile = (string) $case['spec_file'];
        if (!isset(self::$specCache[$specFile])) {
            $raw = file_get_contents(self::testdataDir() . '/' . $specFile);
            self::assertNotFalse($raw, "read spec file: $specFile");
            self::$specCache[$specFile] = $raw;
        }

        /** @var array<string, string> $stickyCache */
        $stickyCache = (array) ($case['sticky_cache'] ?? []);
        $sticky = new MemoryStickyHandler($stickyCache);

        $storage = StorageFactory::fromJson(self::$specCache[$specFile]);
        $core = new ABCore($storage, $sticky);

        $evalType = self::evalType((string) $case['eval_type']);
        $evalMode = (string) ($case['eval_mode'] ?? 'single');

        if ($evalMode === 'distribution') {
            $userPrefix = (string) $case['user_prefix'];
            $userCount = (int) $case['user_count'];
            $key = (string) $case['key'];

            $results = [];
            for ($i = 0; $i < $userCount; $i++) {
                $userId = $userPrefix . '-' . $i;
                $result = $core->evaluate(new User('', $userId), $key, $evalType);
                $results[] = [
                    'user_id' => $userId,
                    'variant_id' => $result->variantId,
                ];
            }
            usort($results, static fn (array $a, array $b): int => $a['user_id'] <=> $b['user_id']);

            self::assertEquals($expected, $results, 'distribution result should match golden');
            return;
        }

        /** @var array<string, mixed> $userData */
        $userData = (array) ($case['user'] ?? []);
        $user = new User(
            (string) ($userData['anon_id'] ?? ''),
            (string) ($userData['login_id'] ?? ''),
            (array) ($userData['ab_user_props'] ?? []),
        );

        // expected_error 模式：fixture 标本 case 应抛错；只验"有异常"，与
        // conformance/runners/php/ab_core_evaluation.py 的 result_comparator 对齐。
        if (!empty($case['expected_error'])) {
            $thrown = false;
            try {
                $core->evaluate($user, (string) $case['key'], $evalType);
            } catch (\Throwable $e) {
                $thrown = true;
            }
            self::assertTrue($thrown, 'expected error from evaluate');
            return;
        }

        $result = $core->evaluate($user, (string) $case['key'], $evalType);

        $actual = [
            'variant_id' => $result->variantId,
            'check_gate' => $result->checkFeatureGate(),
            'variant_params' => $result->variantParamValue === [] ? null : $result->variantParamValue,
        ];

        // 与 conformance/runners/php/ab_core_evaluation.py 的 result_comparator 对齐：
        // golden variant_params==null 时容忍 actual 实际有 params 值（adapter 测的是
        // gate / config / experiment，gate 不携带 params；fixture 用 single-key eval 不验
        // 完整 variant_params）。
        if (is_array($expected) && array_key_exists('variant_params', $expected)
            && $expected['variant_params'] === null) {
            $actual['variant_params'] = null;
        }

        self::assertEquals($expected, $actual, 'single eval result should match golden');
    }

    private static function evalType(string $s): int
    {
        return match ($s) {
            'gate' => ABCore::TYPE_GATE,
            'config' => ABCore::TYPE_CONFIG,
            'experiment' => ABCore::TYPE_EXPERIMENT,
            default => throw new \RuntimeException("unknown eval_type: $s"),
        };
    }

    private static function testdataDir(): string
    {
        return dirname(__DIR__) . '/testdata';
    }
}

/**
 * 内存级 sticky handler（仅供派生测试使用，与 conformance adapter 行为对齐）。
 */
final class MemoryStickyHandler implements StickyHandlerInterface
{
    /**
     * @param array<string, string> $data
     */
    public function __construct(private array $data = [])
    {
    }

    public function getStickyResult(string $key): ?string
    {
        return $this->data[$key] ?? null;
    }

    public function setStickyResult(string $key, string $result): void
    {
        $this->data[$key] = $result;
    }
}
