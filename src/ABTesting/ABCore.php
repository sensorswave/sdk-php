<?php

declare(strict_types=1);

namespace SensorsWave\ABTesting;

use DateTimeImmutable;
use JsonException;
use SensorsWave\ABTesting\Model\ABSpec;
use SensorsWave\ABTesting\Model\Condition;
use SensorsWave\ABTesting\Model\Rule;
use SensorsWave\Contract\StickyHandlerInterface;
use SensorsWave\Model\User;

/**
 * A/B 求值核心。
 */
final class ABCore
{
    public const TYPE_GATE = 1;
    public const TYPE_CONFIG = 2;
    public const TYPE_EXPERIMENT = 3;

    public function __construct(
        private Storage $storage,
        private readonly ?StickyHandlerInterface $stickyHandler = null,
    )
    {
    }

    /**
     * 执行单个 key 的求值。
     */
    public function evaluate(User $user, string $key, ?int $type = null): ABResult
    {
        $spec = $this->storage->getSpec($key);
        if ($spec === null) {
            return new ABResult();
        }

        if ($type !== null && $spec->type !== $type) {
            return new ABResult();
        }

        return $this->evaluateSpec($user, $spec);
    }

    /**
     * 批量评估当前 storage 中的全部 spec。
     *
     * @return list<ABResult>
     */
    public function evaluateAll(User $user): array
    {
        $results = [];
        foreach ($this->storage->allSpecs() as $spec) {
            $result = $this->evaluateSpec($user, $spec);
            if ($result->id > 0) {
                $results[] = $result;
            }
        }

        return $results;
    }

    /**
     * 导出当前 storage 快照。
     *
     * @throws JsonException
     */
    public function getABSpecs(): string
    {
        return json_encode($this->storage->toPayload(), JSON_THROW_ON_ERROR);
    }

    /**
     * 替换当前 storage。
     */
    public function replaceStorage(Storage $storage): void
    {
        $this->storage = $storage;
    }

    /**
     * 返回当前 storage 的更新时间。
     */
    public function storageUpdateTime(): int
    {
        return $this->storage->updateTime;
    }

    /**
     * 执行 spec 求值。
     */
    private function evaluateSpec(User $user, ABSpec $spec): ABResult
    {
        if (!$spec->enabled) {
            return new ABResult();
        }

        $evalId = $this->getEvalId($user, $spec);
        if ($evalId === '') {
            return new ABResult();
        }

        $result = new ABResult(
            id: $spec->id,
            key: $spec->key,
            type: $spec->type,
            disableImpress: $spec->disableImpress,
        );
        $stickyKey = null;

        if ($spec->sticky && $this->stickyHandler !== null) {
            $stickyKey = $spec->id . '-' . $evalId;
            $cached = $this->stickyHandler->getStickyResult($stickyKey);
            if (is_string($cached) && $cached !== '') {
                /** @var array<string, mixed> $decoded */
                $decoded = json_decode($cached, true) ?? [];
                $variantId = $decoded['v'] ?? null;
                if (is_string($variantId)) {
                    $result->variantId = $variantId;
                    $result->variantParamValue = $spec->variantValues[$variantId] ?? [];
                    return $result;
                }
            }
        }

        $pass = false;
        if ($this->evaluateOverrideRules($user, $spec, $evalId, $result)) {
            return $this->finalizeGateResult($spec, $result, true);
        }

        if ($this->evaluateTrafficRules($user, $spec, $evalId, $result)) {
            return $this->finalizeGateResult($spec, $result, false);
        }

        if ($this->evaluateGateRules($user, $spec, $evalId, $result)) {
            $pass = true;
        }

        if ($spec->type === self::TYPE_EXPERIMENT && $pass && $result->variantId === null) {
            $this->evaluateGroupRules($user, $spec, $evalId, $result);
        }

        $result = $this->finalizeGateResult($spec, $result, $pass);
        if ($stickyKey !== null && $result->variantId !== null) {
            $this->stickyHandler?->setStickyResult($stickyKey, json_encode(['v' => $result->variantId], JSON_THROW_ON_ERROR));
        }

        return $result;
    }

    /**
     * 处理 override 规则。
     */
    private function evaluateOverrideRules(User $user, ABSpec $spec, string $evalId, ABResult $result): bool
    {
        $rules = $spec->rules['OVERRIDE'] ?? [];
        foreach ($rules as $rule) {
            $pass = $this->evaluateRule($user, $rule, $evalId);
            if ($pass) {
                if ($rule->override !== null) {
                    $result->variantId = $rule->override;
                    $result->variantParamValue = $spec->variantValues[$rule->override] ?? [];
                }

                return true;
            }
        }

        return false;
    }

    /**
     * 处理 traffic 规则。
     */
    private function evaluateTrafficRules(User $user, ABSpec $spec, string $evalId, ABResult $result): bool
    {
        $rules = $spec->rules['TRAFFIC'] ?? [];
        foreach ($rules as $rule) {
            $pass = $this->evaluateRule($user, $rule, $evalId);
            if (!$pass) {
                if ($rule->override !== null) {
                    $result->variantId = $rule->override;
                    $result->variantParamValue = $spec->variantValues[$rule->override] ?? [];
                }

                return true;
            }
        }

        return false;
    }

    /**
     * 处理 gate 规则。
     */
    private function evaluateGateRules(User $user, ABSpec $spec, string $evalId, ABResult $result): bool
    {
        $rules = $spec->rules['GATE'] ?? [];
        foreach ($rules as $rule) {
            $pass = $this->evaluateRule($user, $rule, $evalId);
            if ($pass) {
                if ($rule->override !== null) {
                    $result->variantId = $rule->override;
                    $result->variantParamValue = $spec->variantValues[$rule->override] ?? [];
                }

                return true;
            }
        }

        return false;
    }

    /**
     * 处理实验分组规则。
     */
    private function evaluateGroupRules(User $user, ABSpec $spec, string $evalId, ABResult $result): void
    {
        $rules = $spec->rules['GROUP'] ?? [];
        foreach ($rules as $rule) {
            if ($this->evaluateRule($user, $rule, $evalId) && $rule->override !== null) {
                $result->variantId = $rule->override;
                $result->variantParamValue = $spec->variantValues[$rule->override] ?? [];
                return;
            }
        }
    }

    /**
     * 处理单条规则。
     */
    private function evaluateRule(User $user, Rule $rule, string $evalId): bool
    {
        if ($rule->rollout === 0.0) {
            return false;
        }

        foreach ($rule->conditions as $condition) {
            if (!$this->evaluateCondition($user, $condition, $evalId)) {
                return false;
            }
        }

        if ($rule->rollout === 100.0) {
            return true;
        }

        return $this->hashModulo($evalId, $rule->salt, 10000) < (int) round($rule->rollout * 100);
    }

    /**
     * 求值单个条件。
     */
    private function evaluateCondition(User $user, Condition $condition, string $evalId): bool
    {
        $left = match (strtolower($condition->fieldClass)) {
            'common' => strtolower($condition->field) === 'public' ? true : null,
            'ffuser' => $this->ffUserValue($user, $condition->field),
            'props' => $user->abUserProperties()->get($condition->field),
            'bucket' => $condition->field,
            'target' => null,
            default => $condition->field,
        };

        $operator = strtolower($condition->operator);
        return match ($operator) {
            'gt' => $this->compareNumbers($left, $condition->value, static fn (float $a, float $b): bool => $a > $b),
            'gte' => $this->compareNumbers($left, $condition->value, static fn (float $a, float $b): bool => $a >= $b),
            'lt' => $this->compareNumbers($left, $condition->value, static fn (float $a, float $b): bool => $a < $b),
            'lte' => $this->compareNumbers($left, $condition->value, static fn (float $a, float $b): bool => $a <= $b),
            'version_gt' => $this->compareVersions($left, $condition->value, static fn (int $cmp): bool => $cmp > 0),
            'version_gte' => $this->compareVersions($left, $condition->value, static fn (int $cmp): bool => $cmp >= 0),
            'version_lt' => $this->compareVersions($left, $condition->value, static fn (int $cmp): bool => $cmp < 0),
            'version_lte' => $this->compareVersions($left, $condition->value, static fn (int $cmp): bool => $cmp <= 0),
            'version_eq' => $this->compareVersions($left, $condition->value, static fn (int $cmp): bool => $cmp === 0),
            'version_neq' => $this->compareVersions($left, $condition->value, static fn (int $cmp): bool => $cmp !== 0),
            'any_of_case_sensitive' => $this->arrayAny($left, $condition->value, true),
            'none_of_case_sensitive' => !$this->arrayAny($left, $condition->value, true),
            'any_of_case_insensitive' => $this->arrayAny($left, $condition->value, false),
            'none_of_case_insensitive' => !$this->arrayAny($left, $condition->value, false),
            'is_null' => $left === null,
            'is_not_null' => $left !== null,
            'is_true' => $left === true,
            'is_false' => $left === false,
            'eq' => $this->deepEqual($left, $condition->value),
            'neq' => !$this->deepEqual($left, $condition->value),
            'before' => $this->compareTimes($left, $condition->value, static fn (int $cmp): bool => $cmp < 0),
            'after' => $this->compareTimes($left, $condition->value, static fn (int $cmp): bool => $cmp > 0),
            'bucket_set' => is_string($left) && is_string($condition->value) ? $this->bucketSetContains($evalId, $left, $condition->value) : false,
            'gate_pass' => $this->evaluateGateDependency($user, $condition->field, false),
            'gate_fail' => $this->evaluateGateDependency($user, $condition->field, true),
            default => false,
        };
    }

    /**
     * 返回 FFUSER 取值。
     */
    private function ffUserValue(User $user, string $field): mixed
    {
        return match (strtolower($field)) {
            'login_id' => $user->loginId() !== '' ? $user->loginId() : null,
            'anon_id' => $user->anonId() !== '' ? $user->anonId() : null,
            default => null,
        };
    }

    /**
     * 标准化 gate 返回值。
     */
    private function finalizeGateResult(ABSpec $spec, ABResult $result, bool $pass): ABResult
    {
        if ($spec->type === self::TYPE_GATE && $result->variantId === null) {
            $result->variantId = $pass ? 'pass' : 'fail';
        }

        return $result;
    }

    /**
     * 计算求值主体。
     */
    private function getEvalId(User $user, ABSpec $spec): string
    {
        return match (strtoupper($spec->subjectId)) {
            'LOGIN_ID' => $user->loginId(),
            'ANON_ID' => $user->anonId(),
            default => $user->loginId() !== '' ? $user->loginId() : $user->anonId(),
        };
    }

    /**
     * 判断左值是否命中数组。
     */
    private function arrayAny(mixed $left, mixed $right, bool $caseSensitive): bool
    {
        if (!is_array($right)) {
            return false;
        }

        $leftString = $this->stringify($left);
        foreach ($right as $item) {
            $rightString = $this->stringify($item);
            if ($caseSensitive && $leftString === $rightString) {
                return true;
            }

            if (!$caseSensitive && strcasecmp($leftString, $rightString) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * 比较基础值。
     */
    private function deepEqual(mixed $left, mixed $right): bool
    {
        if ($right === null) {
            return $left === null || $left === '';
        }

        return $left === $right;
    }

    /**
     * 执行数字比较。
     */
    private function compareNumbers(mixed $left, mixed $right, callable $comparator): bool
    {
        $leftNumber = $this->toNumber($left);
        $rightNumber = $this->toNumber($right);
        if ($leftNumber === null || $rightNumber === null) {
            return false;
        }

        return $comparator($leftNumber, $rightNumber);
    }

    /**
     * 执行版本比较。
     */
    private function compareVersions(mixed $left, mixed $right, callable $comparator): bool
    {
        if (!is_string($left) || !is_string($right) || $left === '' || $right === '') {
            return false;
        }

        $leftParts = $this->splitVersion($left);
        $rightParts = $this->splitVersion($right);
        if ($leftParts === null || $rightParts === null) {
            return false;
        }

        return $comparator($this->compareVersionParts($leftParts, $rightParts));
    }

    /**
     * 执行时间比较。
     */
    private function compareTimes(mixed $left, mixed $right, callable $comparator): bool
    {
        $leftTime = $this->toTimestamp($left);
        $rightTime = $this->toTimestamp($right);
        if ($leftTime === null || $rightTime === null) {
            return false;
        }

        return $comparator($leftTime <=> $rightTime);
    }

    /**
     * 执行 gate 依赖判断。
     */
    private function evaluateGateDependency(User $user, string $key, bool $invert): bool
    {
        $result = $this->evaluate($user, $key, self::TYPE_GATE);
        $pass = $result->checkFeatureGate();

        return $invert ? !$pass : $pass;
    }

    /**
     * 判断 BUCKET_SET 是否包含当前用户桶位。
     */
    private function bucketSetContains(string $evalId, string $salt, string $encodedBitmap): bool
    {
        $bitmap = @hex2bin($encodedBitmap);
        if ($bitmap === false) {
            return false;
        }

        $bucket = $this->hashModulo($evalId, $salt, 1000);
        $byteIndex = intdiv($bucket, 8);
        $bitIndex = $bucket % 8;
        if (!isset($bitmap[$byteIndex])) {
            return false;
        }

        $byte = ord($bitmap[$byteIndex]);
        return (($byte >> (7 - $bitIndex)) & 1) === 1;
    }

    /**
     * 将任意值转换为字符串。
     */
    private function stringify(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    }

    /**
     * 转换数值。
     */
    private function toNumber(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        return null;
    }

    /**
     * 解析版本号。
     *
     * @return list<int>|null
     */
    private function splitVersion(string $value): ?array
    {
        $segments = explode('-', $value, 2);
        $version = $segments[0];
        if ($version === '') {
            return null;
        }

        $parts = [];
        foreach (explode('.', $version) as $segment) {
            if ($segment === '' || !ctype_digit($segment)) {
                return null;
            }
            $parts[] = (int) $segment;
        }

        return $parts;
    }

    /**
     * 比较版本号数组。
     *
     * @param list<int> $left
     * @param list<int> $right
     */
    private function compareVersionParts(array $left, array $right): int
    {
        $max = max(count($left), count($right));
        for ($index = 0; $index < $max; $index++) {
            $leftValue = $left[$index] ?? 0;
            $rightValue = $right[$index] ?? 0;
            if ($leftValue < $rightValue) {
                return -1;
            }
            if ($leftValue > $rightValue) {
                return 1;
            }
        }

        return 0;
    }

    /**
     * 解析时间戳。
     */
    private function toTimestamp(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return 0;
        }

        if (is_int($value)) {
            return $this->normalizeTimestamp($value);
        }

        if (is_float($value)) {
            return $this->normalizeTimestamp((int) $value);
        }

        if (is_string($value)) {
            if (is_numeric($value)) {
                return $this->normalizeTimestamp((int) $value);
            }

            try {
                $date = new DateTimeImmutable($value);
                return $date->getTimestamp();
            } catch (\Exception) {
                return null;
            }
        }

        return null;
    }

    /**
     * 标准化秒/毫秒时间戳。
     */
    private function normalizeTimestamp(int $value): int
    {
        if ($value > 50_000_000_000) {
            return (int) floor($value / 1000);
        }

        return $value;
    }

    /**
     * 计算 rollout 哈希。
     */
    private function hashModulo(string $key, string $salt, int $modulus): int
    {
        $binary = hash('sha256', $key . '.' . $salt, true);
        $value = 0;
        for ($index = 0; $index < 8; $index++) {
            $value = (($value * 256) + ord($binary[$index])) % $modulus;
        }

        return $value;
    }
}
