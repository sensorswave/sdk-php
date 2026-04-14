#!/usr/bin/env php
<?php

/**
 * 事件追踪示例 — 演示 Identify、TrackEvent、ProfileSet 等基础用法。
 *
 * 用法:
 *   php example/track_example.php \
 *       --source-token=<your_token> \
 *       --endpoint=<your_endpoint> \
 *       [--anon-id=<anon_id>] \
 *       [--login-id=<login_id>]
 *
 * 生成日期: 2026-04-14 (AI)
 */

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use SensorsWave\Client\Client;
use SensorsWave\Model\User;

// ---------------------------------------------------------------------------
// 参数解析
// ---------------------------------------------------------------------------

$opts = getopt('', [
    'source-token:',
    'endpoint:',
    'anon-id::',
    'login-id::',
]);

$sourceToken = $opts['source-token'] ?? '';
$endpoint    = $opts['endpoint']     ?? 'https://example.sensorswave.com';
$anonId      = $opts['anon-id']      ?? '';
$loginId     = $opts['login-id']     ?? '';

if ($sourceToken === '') {
    fwrite(STDERR, "Error: --source-token is required\n");
    fwrite(STDERR, "Usage: php example/track_example.php --source-token=<token> --endpoint=<url>\n");
    exit(1);
}

// 未指定则自动生成
$now = (string) hrtime(true);
if ($anonId === '') {
    $anonId = 'anon-' . $now;
}
if ($loginId === '') {
    $loginId = 'user-' . $now;
}

// ---------------------------------------------------------------------------
// 创建客户端
// ---------------------------------------------------------------------------

$client = Client::create($endpoint, $sourceToken);

$user = new User(anonId: $anonId, loginId: $loginId);

echo "Endpoint:  {$endpoint}\n";
echo "AnonID:    {$anonId}\n";
echo "LoginID:   {$loginId}\n\n";

// ---------------------------------------------------------------------------
// 1. Identify — 关联匿名 ID 与登录 ID
// ---------------------------------------------------------------------------

echo "== Identify ==\n";
$client->identify($user);
echo "  identify ok\n\n";

// ---------------------------------------------------------------------------
// 2. TrackEvent — 追踪自定义事件
// ---------------------------------------------------------------------------

echo "== TrackEvent ==\n";
$client->trackEvent($user, 'PurchaseEvent', [
    'product_id' => 'SKU-001',
    'price'      => 19.9,
    'currency'   => 'USD',
]);
echo "  track PurchaseEvent ok\n\n";

// ---------------------------------------------------------------------------
// 3. ProfileSet — 设置用户属性
// ---------------------------------------------------------------------------

echo "== ProfileSet ==\n";
$client->profileSet($user, [
    'name'        => 'Alice',
    'email'       => 'alice@example.com',
    'signup_date' => date('c'),
]);
echo "  profileSet ok\n\n";

// ---------------------------------------------------------------------------
// 完成
// ---------------------------------------------------------------------------

$client->close();
echo "example done\n";
