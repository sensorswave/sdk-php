# EventQueueInterface 重新抽象实现计划

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** 将 EventQueueInterface 从 claim-based batch 模型重构为通用的 payload 透传模型，使接口不绑定特定消费后端。

**Architecture:** 引入 `QueueMessage(receipt, payload)` 值对象替代 `EventBatch(batchId, events)`。接口改为 `enqueue(list<string>)` / `dequeue(int $limit): list<QueueMessage>` / `ack/nack(list<QueueMessage>)`。队列不再感知 payload 类型，1:1 映射 enqueue 元素与 QueueMessage。

**Tech Stack:** PHP 8.2, PHPUnit 11

---

### Task 1: 创建 QueueMessage 值对象 + 更新接口定义

**Files:**
- Create: `src/Storage/QueueMessage.php`
- Modify: `src/Contract/EventQueueInterface.php`

**Step 1: 创建 QueueMessage**

```php
<?php

declare(strict_types=1);

namespace SensorsWave\Storage;

/**
 * 队列消息：不透明 receipt + 原始 payload。
 */
final class QueueMessage
{
    public function __construct(
        public readonly string $receipt,
        public readonly string $payload,
    ) {
    }
}
```

**Step 2: 更新 EventQueueInterface**

```php
<?php

declare(strict_types=1);

namespace SensorsWave\Contract;

use SensorsWave\Storage\QueueMessage;

/**
 * 事件队列抽象。
 */
interface EventQueueInterface
{
    /** @param list<string> $payloads */
    public function enqueue(array $payloads): void;

    /** @return list<QueueMessage> */
    public function dequeue(int $limit): array;

    /** @param list<QueueMessage> $messages */
    public function ack(array $messages): void;

    /** @param list<QueueMessage> $messages */
    public function nack(array $messages): void;
}
```

**Step 3: Commit**

```bash
git add src/Storage/QueueMessage.php src/Contract/EventQueueInterface.php
git commit -m "refactor: introduce QueueMessage and update EventQueueInterface

- Replace EventBatch(batchId, events) with QueueMessage(receipt, payload)
- enqueue accepts list<string> (raw payloads)
- dequeue returns list<QueueMessage>, empty array for empty queue
- ack/nack accept list<QueueMessage> instead of string batchId"
```

> 注意：此 commit 后项目暂时无法编译，因为实现类尚未更新。

---

### Task 2: 重写 MemoryEventQueue（测试替身）

**Files:**
- Modify: `tests/Support/MemoryEventQueue.php`

**Step 1: 重写 MemoryEventQueue**

```php
<?php

declare(strict_types=1);

namespace SensorsWave\Tests\Support;

use SensorsWave\Contract\EventQueueInterface;
use SensorsWave\Storage\QueueMessage;

final class MemoryEventQueue implements EventQueueInterface
{
    /** @var list<string> */
    public array $queued = [];
    /** @var list<QueueMessage> */
    public array $claimed = [];

    public function enqueue(array $payloads): void
    {
        array_push($this->queued, ...$payloads);
    }

    public function dequeue(int $limit): array
    {
        if ($this->queued === []) {
            return [];
        }

        $taken = array_splice($this->queued, 0, max(1, $limit));
        $messages = [];
        foreach ($taken as $payload) {
            $messages[] = new QueueMessage(uniqid('rcpt-', true), $payload);
        }
        array_push($this->claimed, ...$messages);

        return $messages;
    }

    public function ack(array $messages): void
    {
        $receipts = array_map(fn(QueueMessage $m) => $m->receipt, $messages);
        $this->claimed = array_values(array_filter(
            $this->claimed,
            fn(QueueMessage $m) => !in_array($m->receipt, $receipts, true),
        ));
    }

    public function nack(array $messages): void
    {
        $receipts = [];
        foreach ($messages as $message) {
            $receipts[] = $message->receipt;
            array_unshift($this->queued, $message->payload);
        }
        $this->claimed = array_values(array_filter(
            $this->claimed,
            fn(QueueMessage $m) => !in_array($m->receipt, $receipts, true),
        ));
    }
}
```

**Step 2: Commit**

```bash
git add tests/Support/MemoryEventQueue.php
git commit -m "refactor: rewrite MemoryEventQueue for new interface

- enqueue stores raw string payloads (1:1)
- dequeue returns list<QueueMessage> with unique receipts
- ack/nack operate on QueueMessage lists"
```

---

### Task 3: 重写 LocalFileEventQueue

**Files:**
- Modify: `src/Storage/LocalFileEventQueue.php`

**Step 1: 重写实现**

关键变化：
- `enqueue(array $payloads)`: 逐条追加到队列 JSON 数组（不再包装 batch 信封）
- `dequeue(int $limit)`: 取前 `$limit` 条，每条生成 UUID receipt，整批写入单个 claim 文件
- `ack(array $messages)`: 按 receipt 找到 claim 文件并删除
- `nack(array $messages)`: 读 claim 文件，把 payloads 推回队列头部，删除 claim 文件
- 内部存储格式从 `list<{batch_id, events}>` 简化为 `list<string>`（纯 payload 数组）
- claim 文件格式从 `{batch_id, events}` 改为 `{receipt: string, payloads: list<string>}`
- 保留 flock + write-to-temp + rename 的原子写入

```php
<?php

declare(strict_types=1);

namespace SensorsWave\Storage;

use JsonException;
use RuntimeException;
use SensorsWave\Contract\EventQueueInterface;

/**
 * 基于本地文件的事件队列。
 */
final class LocalFileEventQueue implements EventQueueInterface
{
    private readonly string $queuePath;
    private readonly string $claimDirectory;
    private readonly string $lockPath;

    public function __construct(
        string $queuePath = '',
        string $claimDirectory = '',
    ) {
        $resolvedQueuePath = $queuePath !== ''
            ? $queuePath
            : sys_get_temp_dir() . '/sensorswave-event-queue.json';
        $resolvedClaimDirectory = $claimDirectory !== ''
            ? $claimDirectory
            : sys_get_temp_dir() . '/sensorswave-event-claims';

        $this->queuePath = $resolvedQueuePath;
        $this->claimDirectory = $resolvedClaimDirectory;
        $this->lockPath = $resolvedQueuePath . '.lock';
    }

    public function enqueue(array $payloads): void
    {
        $this->withLock(function () use ($payloads): void {
            $queue = $this->readQueue();
            array_push($queue, ...$payloads);
            $this->writeQueue($queue);
        });
    }

    public function dequeue(int $limit): array
    {
        return $this->withLock(function () use ($limit): array {
            $queue = $this->readQueue();
            if ($queue === []) {
                return [];
            }

            $taken = array_splice($queue, 0, max(1, $limit));
            $this->writeQueue($queue);

            $receipt = uniqid('rcpt-', true);
            $this->writeClaim($receipt, $taken);

            $messages = [];
            foreach ($taken as $payload) {
                $messages[] = new QueueMessage($receipt, $payload);
            }

            return $messages;
        });
    }

    public function ack(array $messages): void
    {
        $this->withLock(function () use ($messages): void {
            $receipts = array_unique(array_map(
                fn(QueueMessage $m) => $m->receipt,
                $messages,
            ));
            foreach ($receipts as $receipt) {
                $claimPath = $this->claimPath($receipt);
                if (is_file($claimPath)) {
                    @unlink($claimPath);
                }
            }
        });
    }

    public function nack(array $messages): void
    {
        $this->withLock(function () use ($messages): void {
            $receipts = array_unique(array_map(
                fn(QueueMessage $m) => $m->receipt,
                $messages,
            ));

            $queue = $this->readQueue();
            foreach ($receipts as $receipt) {
                $claimPath = $this->claimPath($receipt);
                if (!is_file($claimPath)) {
                    continue;
                }

                $contents = file_get_contents($claimPath);
                if ($contents === false || $contents === '') {
                    @unlink($claimPath);
                    continue;
                }

                try {
                    /** @var list<string> $payloads */
                    $payloads = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
                } catch (JsonException) {
                    @unlink($claimPath);
                    continue;
                }

                array_unshift($queue, ...$payloads);
                @unlink($claimPath);
            }
            $this->writeQueue($queue);
        });
    }

    /** @return list<string> */
    private function readQueue(): array
    {
        if (!is_file($this->queuePath)) {
            return [];
        }

        $contents = file_get_contents($this->queuePath);
        if ($contents === false || $contents === '') {
            return [];
        }

        try {
            /** @var list<string> $decoded */
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
            return $decoded;
        } catch (JsonException) {
            return [];
        }
    }

    /** @param list<string> $queue */
    private function writeQueue(array $queue): void
    {
        $directory = dirname($this->queuePath);
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException('failed to create event queue directory');
        }

        try {
            $json = json_encode($queue, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('failed to encode event queue payload', 0, $exception);
        }

        $tempPath = $this->queuePath . '.' . uniqid('tmp', true);
        if (file_put_contents($tempPath, $json) === false) {
            throw new RuntimeException('failed to write event queue');
        }

        if (!rename($tempPath, $this->queuePath)) {
            @unlink($tempPath);
            throw new RuntimeException('failed to move event queue into place');
        }
    }

    /** @param list<string> $payloads */
    private function writeClaim(string $receipt, array $payloads): void
    {
        if (!is_dir($this->claimDirectory) && !mkdir($this->claimDirectory, 0777, true) && !is_dir($this->claimDirectory)) {
            throw new RuntimeException('failed to create event claim directory');
        }

        try {
            $json = json_encode($payloads, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('failed to encode event claim', 0, $exception);
        }

        $claimPath = $this->claimPath($receipt);
        $tempPath = $claimPath . '.' . uniqid('tmp', true);
        if (file_put_contents($tempPath, $json) === false) {
            throw new RuntimeException('failed to write event claim');
        }

        if (!rename($tempPath, $claimPath)) {
            @unlink($tempPath);
            throw new RuntimeException('failed to move event claim into place');
        }
    }

    private function claimPath(string $receipt): string
    {
        return $this->claimDirectory . '/' . str_replace(['/', '\\'], '-', $receipt) . '.json';
    }

    /**
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    private function withLock(callable $callback): mixed
    {
        $directory = dirname($this->lockPath);
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException('failed to create event queue lock directory');
        }

        $handle = fopen($this->lockPath, 'c+');
        if ($handle === false) {
            throw new RuntimeException('failed to open event queue lock file');
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException('failed to lock event queue');
            }

            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
```

**Step 2: Commit**

```bash
git add src/Storage/LocalFileEventQueue.php
git commit -m "refactor: rewrite LocalFileEventQueue for new interface

- enqueue stores raw string payloads (1:1, no batch envelope)
- dequeue returns list<QueueMessage> with shared receipt per dequeue call
- ack/nack operate on QueueMessage lists by receipt
- retain flock + atomic write-to-temp + rename"
```

---

### Task 4: 重写 RedisEventQueue

**Files:**
- Modify: `src/Storage/RedisEventQueue.php`

**Step 1: 重写实现**

关键变化：
- `enqueue(array $payloads)`: `RPUSH key ...payloads`（不再包装 batch 信封，利用 variadic 批量推入）
- `dequeue(int $limit)`: Lua 脚本简化为 `LRANGE(0, limit-1)` + `LTRIM(limit, -1)` + `SETEX claim`，返回的每条 payload 直接包装为 QueueMessage
- `ack(array $messages)`: `DEL claimKey`
- `nack(array $messages)`: Lua 脚本读 claim + 逆序 LPUSH 回队列 + DEL claim
- 删除 `maxBatches` 构造参数（不再需要）
- 删除 PHP 侧的 overflow push-back 逻辑

```php
<?php

declare(strict_types=1);

namespace SensorsWave\Storage;

use JsonException;
use RuntimeException;
use SensorsWave\Contract\EventQueueInterface;
use SensorsWave\Contract\RedisClientInterface;

/**
 * 基于 Redis 的事件队列。
 */
final class RedisEventQueue implements EventQueueInterface
{
    /**
     * 默认 claim TTL：1 小时。
     */
    private const DEFAULT_CLAIM_TTL_SECONDS = 3600;

    /**
     * Lua: 原子 dequeue。
     * KEYS[1] = queueKey, KEYS[2] = claimKey
     * ARGV[1] = limit, ARGV[2] = claimTtlSeconds
     */
    private const LUA_DEQUEUE = <<<'LUA'
        local items = redis.call('LRANGE', KEYS[1], 0, ARGV[1] - 1)
        if #items == 0 then
            return false
        end
        redis.call('LTRIM', KEYS[1], ARGV[1], -1)
        local payload = cjson.encode(items)
        redis.call('SETEX', KEYS[2], ARGV[2], payload)
        return payload
        LUA;

    /**
     * Lua: 原子 nack，将 payloads 逐条推回队列头部（保持原顺序）。
     * KEYS[1] = claimKey, KEYS[2] = queueKey
     */
    private const LUA_NACK = <<<'LUA'
        local payload = redis.call('GET', KEYS[1])
        if not payload then
            return 0
        end
        local items = cjson.decode(payload)
        for i = #items, 1, -1 do
            redis.call('LPUSH', KEYS[2], items[i])
        end
        redis.call('DEL', KEYS[1])
        return 1
        LUA;

    public function __construct(
        private readonly RedisClientInterface $redis,
        private readonly string $queueKey = '{sensorswave}:event_queue',
        private readonly string $claimPrefix = '{sensorswave}:event_claim:',
        private readonly int $claimTtlSeconds = self::DEFAULT_CLAIM_TTL_SECONDS,
    ) {
    }

    public function enqueue(array $payloads): void
    {
        if ($payloads === []) {
            return;
        }
        $this->redis->rPush($this->queueKey, ...$payloads);
    }

    public function dequeue(int $limit): array
    {
        $receipt  = uniqid('rcpt-', true);
        $claimKey = $this->claimPrefix . $receipt;

        /** @var string|false $payload */
        $payload = $this->redis->eval(
            self::LUA_DEQUEUE,
            [$this->queueKey, $claimKey],
            [$limit, $this->claimTtlSeconds],
        );

        if (!is_string($payload) || $payload === '') {
            return [];
        }

        try {
            /** @var list<string> $items */
            $items = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('failed to decode claim payload', 0, $exception);
        }

        $messages = [];
        foreach ($items as $item) {
            $messages[] = new QueueMessage($receipt, $item);
        }

        return $messages;
    }

    public function ack(array $messages): void
    {
        $receipts = array_unique(array_map(
            fn(QueueMessage $m) => $m->receipt,
            $messages,
        ));
        foreach ($receipts as $receipt) {
            $this->redis->del($this->claimPrefix . $receipt);
        }
    }

    public function nack(array $messages): void
    {
        $receipts = array_unique(array_map(
            fn(QueueMessage $m) => $m->receipt,
            $messages,
        ));
        foreach ($receipts as $receipt) {
            $this->redis->eval(
                self::LUA_NACK,
                [$this->claimPrefix . $receipt, $this->queueKey],
            );
        }
    }
}
```

**Step 2: Commit**

```bash
git add src/Storage/RedisEventQueue.php
git commit -m "refactor: rewrite RedisEventQueue for new interface

- enqueue uses RPUSH with variadic payloads (1:1, no batch envelope)
- dequeue Lua simplified: LRANGE + LTRIM + SETEX, no batch merging
- ack/nack operate on QueueMessage lists by receipt
- remove maxBatches parameter and overflow push-back logic"
```

---

### Task 5: 适配 Client 和 SendCommand

**Files:**
- Modify: `src/Client/Client.php:425-447` (`flushPendingTrackMessages`)
- Modify: `src/Worker/SendCommand.php:29-68` (`run`)

**Step 1: 修改 Client::flushPendingTrackMessages()**

旧代码将 pendingMessages 拼接为 JSON 数组字符串再 decode，新代码直接传 `$this->pendingMessages`（已经是 `list<string>`）。

```php
// 旧 (lines 425-447):
private function flushPendingTrackMessages(): void
{
    if ($this->pendingMessages === []) {
        return;
    }

    $body = '[' . implode(',', $this->pendingMessages) . ']';
    $this->pendingMessages = [];
    $this->pendingBodySize = 0;
    $this->lastTrackFlushAtMs = $this->nowMs();

    try {
        /** @var list<array<string, mixed>> $events */
        $events = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        $this->config->eventQueue->enqueue($events);
    } catch (\Throwable $throwable) {
        $this->config->logger->error(
            'event queue enqueue failed',
            ['error' => $throwable->getMessage()]
        );
        $this->notifyTrackFailure($body, $throwable, null);
    }
}

// 新:
private function flushPendingTrackMessages(): void
{
    if ($this->pendingMessages === []) {
        return;
    }

    $payloads = $this->pendingMessages;
    $this->pendingMessages = [];
    $this->pendingBodySize = 0;
    $this->lastTrackFlushAtMs = $this->nowMs();

    try {
        $this->config->eventQueue->enqueue($payloads);
    } catch (\Throwable $throwable) {
        $this->config->logger->error(
            'event queue enqueue failed',
            ['error' => $throwable->getMessage()]
        );
        $body = '[' . implode(',', $payloads) . ']';
        $this->notifyTrackFailure($body, $throwable, null);
    }
}
```

**Step 2: 修改 SendCommand::run()**

```php
// 旧 (lines 29-68):
public function run(int $maxItems = 50): int
{
    $status = 0;
    while (true) {
        $batch = $this->config->eventQueue->dequeue($maxItems);
        if ($batch === null) {
            return $status;
        }

        try {
            $body = json_encode($batch->events, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            ...
            $this->config->eventQueue->ack($batch->batchId);
            ...
        }
        ...
        if ($this->deliver($request)) {
            $this->config->eventQueue->ack($batch->batchId);
            continue;
        }

        $this->config->eventQueue->nack($batch->batchId);
        ...
    }
}

// 新:
public function run(int $limit = 50): int
{
    $status = 0;
    while (true) {
        $messages = $this->config->eventQueue->dequeue($limit);
        if ($messages === []) {
            return $status;
        }

        $body = '[' . implode(',', array_map(
            fn(QueueMessage $m) => $m->payload, $messages
        )) . ']';

        $request = new Request(
            'POST',
            $this->normalizeEndpoint($this->endpoint) . $this->normalizeUriPath($this->config->trackUriPath, '/in/track'),
            [
                'Content-Type' => 'application/json',
                'SourceToken' => $this->sourceToken,
            ],
            $body
        );

        if ($this->deliver($request)) {
            $this->config->eventQueue->ack($messages);
            continue;
        }

        $this->config->eventQueue->nack($messages);
        $status = 1;
        return $status;
    }
}
```

注意：移除了 `json_encode` 失败的 try-catch 分支，因为 payload 已经是原始 JSON 字符串，拼接不会失败。如果某条 payload 本身就是损坏的 JSON，那是生产端的责任，不在队列层处理。

**Step 3: 添加 use 语句**

在 `SendCommand.php` 顶部添加：
```php
use SensorsWave\Storage\QueueMessage;
```

**Step 4: Commit**

```bash
git add src/Client/Client.php src/Worker/SendCommand.php
git commit -m "refactor: adapt Client and SendCommand for new EventQueueInterface

- Client: pass pendingMessages directly as list<string>, skip json_decode
- SendCommand: receive list<QueueMessage>, concat payloads for HTTP body
- Remove unnecessary json_encode/decode round-trip"
```

---

### Task 6: 更新所有测试

**Files:**
- Modify: `tests/Storage/LocalFileEventQueueTest.php`
- Modify: `tests/Storage/RedisEventQueueTest.php`
- Modify: `tests/Worker/SendCommandTest.php`
- Modify: `tests/Track/ClientTrackingTest.php`
- Modify: `tests/ABTesting/ClientABTest.php`

**Step 1: 重写 LocalFileEventQueueTest**

```php
<?php

declare(strict_types=1);

namespace SensorsWave\Tests\Storage;

use PHPUnit\Framework\TestCase;
use SensorsWave\Storage\LocalFileEventQueue;

final class LocalFileEventQueueTest extends TestCase
{
    private string $queuePath;
    private string $claimDir;

    protected function setUp(): void
    {
        $suffix = uniqid('', true);
        $this->queuePath = sys_get_temp_dir() . '/sensorswave-event-queue-' . $suffix . '.json';
        $this->claimDir = sys_get_temp_dir() . '/sensorswave-event-claims-' . $suffix;
    }

    protected function tearDown(): void
    {
        @unlink($this->queuePath);
        if (is_dir($this->claimDir)) {
            $entries = scandir($this->claimDir);
            if (is_array($entries)) {
                foreach ($entries as $entry) {
                    if ($entry === '.' || $entry === '..') {
                        continue;
                    }
                    @unlink($this->claimDir . '/' . $entry);
                }
            }
            @rmdir($this->claimDir);
        }
    }

    public function testEnqueueDequeueAckLifecycle(): void
    {
        $queue = new LocalFileEventQueue($this->queuePath, $this->claimDir);

        $queue->enqueue(['{"event":"PageView"}', '{"event":"Purchase"}']);

        $messages = $queue->dequeue(50);
        self::assertCount(2, $messages);
        self::assertSame('{"event":"PageView"}', $messages[0]->payload);
        self::assertSame('{"event":"Purchase"}', $messages[1]->payload);

        $queue->ack($messages);
        self::assertSame([], $queue->dequeue(50));
    }

    public function testNackPutsMessagesBackIntoQueue(): void
    {
        $queue = new LocalFileEventQueue($this->queuePath, $this->claimDir);

        $queue->enqueue(['{"event":"RetryEvent"}']);

        $messages = $queue->dequeue(50);
        self::assertCount(1, $messages);

        $queue->nack($messages);

        $retried = $queue->dequeue(50);
        self::assertCount(1, $retried);
        self::assertSame('{"event":"RetryEvent"}', $retried[0]->payload);
    }
}
```

**Step 2: 重写 RedisEventQueueTest**

```php
<?php

declare(strict_types=1);

namespace SensorsWave\Tests\Storage;

use PHPUnit\Framework\TestCase;
use SensorsWave\Storage\RedisEventQueue;
use SensorsWave\Tests\Support\MemoryRedisClient;

final class RedisEventQueueTest extends TestCase
{
    public function testEnqueueDequeueAckLifecycle(): void
    {
        $redis = new MemoryRedisClient();
        $queue = new RedisEventQueue($redis);

        $queue->enqueue(['{"event":"Purchase","amount":10}']);

        $messages = $queue->dequeue(50);
        self::assertCount(1, $messages);
        self::assertSame('{"event":"Purchase","amount":10}', $messages[0]->payload);

        // claim 应存在且有 TTL
        $claimKey = '{sensorswave}:event_claim:' . $messages[0]->receipt;
        self::assertArrayHasKey($claimKey, $redis->store);
        self::assertArrayHasKey($claimKey, $redis->ttls);
        self::assertSame(3600, $redis->ttls[$claimKey]);

        $queue->ack($messages);
        self::assertArrayNotHasKey($claimKey, $redis->store);

        // 队列已空
        self::assertSame([], $queue->dequeue(50));
    }

    public function testDequeueMultipleMessages(): void
    {
        $redis = new MemoryRedisClient();
        $queue = new RedisEventQueue($redis);

        $queue->enqueue(['{"event":"A"}', '{"event":"B"}', '{"event":"C"}']);

        $messages = $queue->dequeue(50);
        self::assertCount(3, $messages);
        self::assertSame('{"event":"A"}', $messages[0]->payload);
        self::assertSame('{"event":"B"}', $messages[1]->payload);
        self::assertSame('{"event":"C"}', $messages[2]->payload);

        $queue->ack($messages);
        self::assertSame([], $queue->dequeue(50));
    }

    public function testDequeueRespectsLimit(): void
    {
        $redis = new MemoryRedisClient();
        $queue = new RedisEventQueue($redis);

        $queue->enqueue(['{"event":"A"}', '{"event":"B"}', '{"event":"C"}', '{"event":"D"}']);

        $messages = $queue->dequeue(2);
        self::assertCount(2, $messages);
        self::assertSame('{"event":"A"}', $messages[0]->payload);
        self::assertSame('{"event":"B"}', $messages[1]->payload);

        // 剩余 2 条仍在队列
        $remaining = $queue->dequeue(50);
        self::assertCount(2, $remaining);
        self::assertSame('{"event":"C"}', $remaining[0]->payload);
        self::assertSame('{"event":"D"}', $remaining[1]->payload);
    }

    public function testNackRequeuesMessages(): void
    {
        $redis = new MemoryRedisClient();
        $queue = new RedisEventQueue($redis);

        $queue->enqueue(['{"event":"First"}', '{"event":"Second"}']);

        $messages = $queue->dequeue(50);
        self::assertCount(2, $messages);

        $queue->nack($messages);

        // claim 应被删除
        $claimKey = '{sensorswave}:event_claim:' . $messages[0]->receipt;
        self::assertArrayNotHasKey($claimKey, $redis->store);

        // 重新 dequeue 应能取到相同 payloads
        $retried = $queue->dequeue(50);
        self::assertCount(2, $retried);
        self::assertSame('{"event":"First"}', $retried[0]->payload);
        self::assertSame('{"event":"Second"}', $retried[1]->payload);
    }

    public function testCustomClaimTtl(): void
    {
        $redis = new MemoryRedisClient();
        $queue = new RedisEventQueue($redis, claimTtlSeconds: 300);

        $queue->enqueue(['{"event":"Test"}']);
        $messages = $queue->dequeue(50);
        self::assertCount(1, $messages);

        $claimKey = '{sensorswave}:event_claim:' . $messages[0]->receipt;
        self::assertSame(300, $redis->ttls[$claimKey]);
    }
}
```

**Step 3: 更新 SendCommandTest**

测试中的 `MemoryEventQueue` 已更新。主要变化：
- `$queue->dequeue(50)` 返回 `array` 而非 `?EventBatch`
- 断言从 `assertNull` 改为 `assertSame([], ...)`
- `$queue->claimed` 类型从 `array<string, EventBatch>` 变为 `list<QueueMessage>`

```php
// line 43: self::assertNull($queue->dequeue(50));
// 改为:
self::assertSame([], $queue->dequeue(50));

// line 66: self::assertNotNull($queue->dequeue(50));
// 改为:
self::assertNotSame([], $queue->dequeue(50));

// line 116: self::assertNull($queue->dequeue(50));
// 改为:
self::assertSame([], $queue->dequeue(50));
```

**Step 4: 更新 ClientTrackingTest**

所有测试中 `$batch = $queue->dequeue(50)` + `$batch->events[N]` 的模式需要改为：

```php
$messages = $queue->dequeue(50);
// 原来: $batch->events[0]['event']
// 现在: json_decode($messages[0]->payload, true)['event']
```

同时 `testTrackEventInvokesFailureHandlerWhenQueueWriteFails` 中的匿名类需更新签名。

**Step 5: 更新 ClientABTest**

同 ClientTrackingTest 的模式，更新 dequeue 返回值访问方式。

**Step 6: 运行全部测试**

Run: `php vendor/bin/phpunit`
Expected: 全部通过

**Step 7: Commit**

```bash
git add tests/
git commit -m "test: update all tests for new EventQueueInterface

- dequeue returns list<QueueMessage>, empty array for empty queue
- access payload via json_decode(message->payload)
- update anonymous queue class signatures in ClientTrackingTest"
```

---

### Task 7: 删除 EventBatch 类 + 清理

**Files:**
- Delete: `src/Storage/EventBatch.php`

**Step 1: 确认无残留引用**

Run: `grep -r 'EventBatch' src/ tests/`
Expected: 无输出

**Step 2: 删除 EventBatch**

```bash
rm src/Storage/EventBatch.php
```

**Step 3: 运行全部测试**

Run: `php vendor/bin/phpunit`
Expected: 全部通过

**Step 4: Commit**

```bash
git add -A
git commit -m "refactor: remove EventBatch class

Replaced by QueueMessage(receipt, payload) in the new interface."
```

---

### Task 8: 最终验证

**Step 1: 运行完整测试套件**

Run: `php vendor/bin/phpunit`
Expected: 全部测试通过，0 错误，0 失败

**Step 2: 确认无残留引用**

Run: `grep -rn 'EventBatch\|batchId\|batch_id\|maxItems' src/ tests/`
Expected: 无旧接口的残留引用
