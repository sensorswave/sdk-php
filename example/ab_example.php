#!/usr/bin/env php
<?php

/**
 * A/B Testing 示例 — 演示 Feature Config、Feature Gate、Experiment 的批量评估。
 *
 * 用法:
 *   php example/ab_example.php \
 *       --source-token=<your_token> \
 *       --project-secret=<your_secret> \
 *       --endpoint=<your_endpoint> \
 *       [--gate-key=example_gate_key] \
 *       [--experiment-key=example_experiment_key] \
 *       [--feature-config-key=example_feature_config_key]
 *
 * 生成日期: 2026-04-14 (AI)
 */

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use SensorsWave\Client\Client;
use SensorsWave\Config\ABConfig;
use SensorsWave\Config\Config;
use SensorsWave\Model\User;

// ---------------------------------------------------------------------------
// 常量
// ---------------------------------------------------------------------------

const TOTAL_USERS              = 1000;
const APP_VERSION              = '11.0';
const DEFAULT_FEATURE_CONFIG_KEY = 'example_feature_config_key';
const DEFAULT_GATE_KEY           = 'example_gate_toggle_key';
const DEFAULT_EXPERIMENT_KEY     = 'example_experiment_key';

// ---------------------------------------------------------------------------
// 参数解析
// ---------------------------------------------------------------------------

$opts = getopt('', [
    'source-token:',
    'project-secret:',
    'endpoint:',
    'gate-key::',
    'experiment-key::',
    'feature-config-key::',
]);

$sourceToken      = $opts['source-token']       ?? '';
$projectSecret    = $opts['project-secret']      ?? '';
$endpoint         = $opts['endpoint']            ?? 'https://example.sensorswave.com';
$gateKey          = $opts['gate-key']            ?? DEFAULT_GATE_KEY;
$experimentKey    = $opts['experiment-key']       ?? DEFAULT_EXPERIMENT_KEY;
$featureConfigKey = $opts['feature-config-key']   ?? DEFAULT_FEATURE_CONFIG_KEY;

if ($sourceToken === '' || $projectSecret === '') {
    fwrite(STDERR, "Error: --source-token and --project-secret are required\n");
    fwrite(STDERR, "Usage: php example/ab_example.php --source-token=<token> --project-secret=<secret> --endpoint=<url>\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// 创建客户端（启用 A/B Testing）
// ---------------------------------------------------------------------------

$client = Client::create($endpoint, $sourceToken, new Config(
    ab: new ABConfig(
        projectSecret: $projectSecret,
    ),
));

// ---------------------------------------------------------------------------
// 生成模拟用户
// ---------------------------------------------------------------------------

$users = buildUsers(TOTAL_USERS, APP_VERSION);

// ---------------------------------------------------------------------------
// 1. Feature Config — 获取功能配置参数
// ---------------------------------------------------------------------------

echo "== Feature Config Example (color config) ==\n";
runFeatureConfigExample($client, $users, $featureConfigKey);

// ---------------------------------------------------------------------------
// 2. Gate — 功能开关
// ---------------------------------------------------------------------------

echo "\n== Gate Example (boolean toggle) ==\n";
runGateExample($client, $users, $gateKey);

// ---------------------------------------------------------------------------
// 3. Experiment — 多变体实验
// ---------------------------------------------------------------------------

echo "\n== Experiment Example (multi-variant) ==\n";
runExperimentExample($client, $users, $experimentKey);

// ---------------------------------------------------------------------------
// 完成
// ---------------------------------------------------------------------------

$client->close();
echo "\nexample done\n";

// ===========================================================================
// 辅助函数
// ===========================================================================

/**
 * 评估 Feature Config 并输出颜色分布。
 *
 * @param list<User> $users
 */
function runFeatureConfigExample(Client $client, array $users, string $key): void
{
    $distribution = [];

    foreach ($users as $user) {
        $result = $client->getFeatureConfig($user, $key);

        // 跳过不存在的 key
        if ($result->key === '') {
            continue;
        }

        $color = $result->getString('color', 'black');
        $distribution[$color] = ($distribution[$color] ?? 0) + 1;
    }

    foreach ($distribution as $color => $count) {
        printf("  variant(color=%s): %d users\n", $color, $count);
    }
}

/**
 * 评估 Gate 并输出通过/未通过统计。
 *
 * @param list<User> $users
 */
function runGateExample(Client $client, array $users, string $key): void
{
    $pass = 0;
    $fail = 0;

    foreach ($users as $user) {
        if ($client->checkFeatureGate($user, $key)) {
            $pass++;
        } else {
            $fail++;
        }
    }

    printf("  gate %s -> pass:%d fail:%d\n", $key, $pass, $fail);
}

/**
 * 评估 Experiment 并输出变体与参数分布。
 *
 * @param list<User> $users
 */
function runExperimentExample(Client $client, array $users, string $key): void
{
    $variantCounts = [];
    $labelCounts   = [];
    $enabledTrue   = 0;

    foreach ($users as $user) {
        $result = $client->getExperiment($user, $key);

        if ($result->variantId === null) {
            continue;
        }

        $variant = $result->variantId;
        $variantCounts[$variant] = ($variantCounts[$variant] ?? 0) + 1;

        $label = $result->getString('label', 'control');
        $labelCounts[$label] = ($labelCounts[$label] ?? 0) + 1;

        if ($result->getBool('enabled', false)) {
            $enabledTrue++;
        }
    }

    foreach ($variantCounts as $variant => $count) {
        printf("  exp variant(%s): %d users\n", $variant, $count);
    }
    foreach ($labelCounts as $label => $count) {
        printf("    -> payload label=%s, hits=%d\n", $label, $count);
    }
    printf("  exp payload enabled=true for %d users (false for %d)\n", $enabledTrue, count($users) - $enabledTrue);
}

/**
 * 生成指定数量的模拟用户，共享相同的 $app_version 属性。
 *
 * @return list<User>
 */
function buildUsers(int $total, string $appVersion): array
{
    $users = [];
    for ($i = 0; $i < $total; $i++) {
        $user = new User(
            anonId:  sprintf('anon-%03d-%s', $i, randomId(12)),
            loginId: sprintf('user-%s', randomId(12)),
        );
        $user = $user->withAbUserProperty('$app_version', $appVersion);
        $users[] = $user;
    }
    return $users;
}

/**
 * 生成指定长度的随机字母数字字符串。
 */
function randomId(int $length): string
{
    $chars  = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $result = '';
    $max    = strlen($chars) - 1;
    for ($i = 0; $i < $length; $i++) {
        $result .= $chars[random_int(0, $max)];
    }
    return $result;
}
