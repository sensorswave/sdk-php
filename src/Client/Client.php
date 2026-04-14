<?php

declare(strict_types=1);

namespace SensorsWave\Client;

use JsonException;
use SensorsWave\ABTesting\ABCore;
use SensorsWave\ABTesting\ABResult;
use SensorsWave\ABTesting\ExposureLogging\ABImpressionFactory;
use SensorsWave\ABTesting\StorageFactory;
use InvalidArgumentException;
use SensorsWave\Config\Config;
use SensorsWave\Exception\EmptyUserIdsException;
use SensorsWave\Exception\IdentifyRequiresBothIdsException;
use SensorsWave\Model\Event;
use SensorsWave\Model\ListProperties;
use SensorsWave\Model\Properties;
use SensorsWave\Model\User;
use SensorsWave\Model\UserPropertyOptions;
use SensorsWave\Tracking\EventSerializer;
use SensorsWave\Tracking\Predefined;
use SensorsWave\Tracking\UserPropertyEventFactory;

/**
 * PHP 风格的 SDK 客户端。
 */
final class Client
{
    public const AB_TYPE_GATE = 1;
    public const AB_TYPE_CONFIG = 2;
    public const AB_TYPE_EXPERIMENT = 3;
    private const MAX_BATCH_SIZE = 50;
    private const MAX_HTTP_BODY_SIZE = 5 * 1024 * 1024;

    private bool $closed = false;
    private ?ABCore $abCore = null;
    private ?int $lastMetaLoadAtMs = null;
    private readonly ?\SensorsWave\Contract\StickyHandlerInterface $stickyHandler;
    /** @var list<string> */
    private array $pendingMessages = [];
    private int $pendingBodySize = 0;
    private ?int $lastTrackFlushAtMs = null;

    private function __construct(
        string $endpoint,
        private readonly string $sourceToken,
        private readonly Config $config,
    ) {
        self::validateEndpoint($endpoint);
        $this->stickyHandler = $config->ab?->stickyHandler;
        $this->abCore = $this->buildABCore($config);
        register_shutdown_function([$this, 'close']);
    }

    /**
     * 创建客户端实例。
     */
    public static function create(string $endpoint, string $sourceToken, ?Config $config = null): self
    {
        return new self($endpoint, $sourceToken, $config ?? new Config());
    }

    /**
     * 关闭客户端。
     */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->flushPendingTrackMessages();
        $this->closed = true;
    }

    /**
     * 立即刷出当前缓冲中的埋点事件。
     */
    public function flush(): void
    {
        if ($this->closed) {
            return;
        }

        $this->flushPendingTrackMessages();
    }

    /**
     * 发送 Identify 事件。
     */
    public function identify(User $user): void
    {
        if ($user->anonId() === '' || $user->loginId() === '') {
            throw new IdentifyRequiresBothIdsException();
        }

        $this->track(Event::create($user->anonId(), $user->loginId(), Predefined::EVENT_IDENTIFY));
    }

    /**
     * 发送自定义事件。
     */
    public function trackEvent(User $user, string $eventName, array|Properties $properties = []): void
    {
        $this->validateUser($user);
        $normalizedProperties = $this->normalizeProperties($properties);

        $event = Event::create($user->anonId(), $user->loginId(), $eventName)
            ->withProperties(Properties::fromArray($normalizedProperties->all()));

        $this->track($event);
    }

    /**
     * 直接发送完整事件对象。
     */
    public function track(Event $event): void
    {
        if ($this->closed) {
            throw new InvalidArgumentException('the client was already closed');
        }

        $event->normalize();
        $this->flushPendingTrackMessagesIfDue();
        $this->enqueueTrackMessage(EventSerializer::serialize($event));
    }

    /**
     * 发送 profile set 事件。
     */
    public function profileSet(User $user, array|Properties $properties): void
    {
        $this->validateUser($user);
        $this->track(UserPropertyEventFactory::profileSet($user, $this->normalizeProperties($properties)));
    }

    /**
     * 发送 profile set once 事件。
     */
    public function profileSetOnce(User $user, array|Properties $properties): void
    {
        $this->validateUser($user);
        $this->track(
            $this->createUserPropertyEvent(
                $user,
                $this->normalizeProperties($properties),
                Predefined::USER_SET_TYPE_SET_ONCE,
                'setOnce'
            )
        );
    }

    /**
     * 发送 profile increment 事件。
     */
    public function profileIncrement(User $user, array|Properties $properties): void
    {
        $this->validateUser($user);
        $this->track(
            $this->createUserPropertyEvent(
                $user,
                $this->normalizeProperties($properties),
                Predefined::USER_SET_TYPE_INCREMENT,
                'increment'
            )
        );
    }

    /**
     * 发送 profile append 事件。
     */
    public function profileAppend(User $user, array|ListProperties $properties): void
    {
        $this->validateUser($user);
        $normalizedProperties = $this->normalizeListProperties($properties);
        $options = UserPropertyOptions::create();
        foreach ($normalizedProperties->all() as $key => $value) {
            $options->append($key, $value);
        }
        $this->track($this->buildUserPropertyEvent($user, $options, Predefined::USER_SET_TYPE_APPEND));
    }

    /**
     * 发送 profile union 事件。
     */
    public function profileUnion(User $user, array|ListProperties $properties): void
    {
        $this->validateUser($user);
        $normalizedProperties = $this->normalizeListProperties($properties);
        $options = UserPropertyOptions::create();
        foreach ($normalizedProperties->all() as $key => $value) {
            $options->union($key, $value);
        }
        $this->track($this->buildUserPropertyEvent($user, $options, Predefined::USER_SET_TYPE_UNION));
    }

    /**
     * 发送 profile unset 事件。
     */
    public function profileUnset(User $user, string ...$propertyKeys): void
    {
        $this->validateUser($user);
        $options = UserPropertyOptions::create();
        foreach ($propertyKeys as $propertyKey) {
            $options->unset($propertyKey);
        }
        $this->track($this->buildUserPropertyEvent($user, $options, Predefined::USER_SET_TYPE_UNSET));
    }

    /**
     * 发送 profile delete 事件。
     */
    public function profileDelete(User $user): void
    {
        $this->validateUser($user);
        $this->track(
            $this->buildUserPropertyEvent(
                $user,
                UserPropertyOptions::create()->delete(),
                Predefined::USER_SET_TYPE_DELETE
            )
        );
    }

    /**
     * 执行 gate 求值。
     */
    public function checkFeatureGate(User $user, string $key): bool
    {
        $this->validateUser($user);
        $this->ensureABCoreFresh();
        if ($this->abCore === null) {
            return false;
        }

        $result = $this->abCore->evaluate($user, $key, self::AB_TYPE_GATE);
        $this->trackABImpressionIfNeeded($user, $result);

        return $result->checkFeatureGate();
    }

    /**
     * 获取 feature config。
     */
    public function getFeatureConfig(User $user, string $key): ABResult
    {
        $this->validateUser($user);
        $this->ensureABCoreFresh();
        if ($this->abCore === null) {
            return new ABResult();
        }

        $result = $this->abCore->evaluate($user, $key, self::AB_TYPE_CONFIG);
        $this->trackABImpressionIfNeeded($user, $result);

        return $result;
    }

    /**
     * 获取 experiment 结果。
     */
    public function getExperiment(User $user, string $key): ABResult
    {
        $this->validateUser($user);
        $this->ensureABCoreFresh();
        if ($this->abCore === null) {
            return new ABResult();
        }

        $result = $this->abCore->evaluate($user, $key, self::AB_TYPE_EXPERIMENT);
        $this->trackABImpressionIfNeeded($user, $result);

        return $result;
    }

    /**
     * 批量获取当前 metadata 中的全部 A/B 结果。
     *
     * @return list<ABResult>
     */
    public function evaluateAll(User $user): array
    {
        $this->validateUser($user);
        $this->ensureABCoreFresh();
        if ($this->abCore === null) {
            return [];
        }

        $results = $this->abCore->evaluateAll($user);
        foreach ($results as $result) {
            $this->trackABImpressionIfNeeded($user, $result);
        }

        return $results;
    }

    /**
     * 导出当前 A/B metadata 快照。
     */
    public function getABSpecs(): string
    {
        $this->ensureABCoreFresh();
        return $this->requireABCore()->getABSpecs();
    }

    /**
     * 校验用户标识。
     */
    private function validateUser(User $user): void
    {
        if ($user->anonId() === '' && $user->loginId() === '') {
            throw new EmptyUserIdsException();
        }
    }

    /**
     * 构建 A/B core。
     */
    private function buildABCore(Config $config): ?ABCore
    {
        if ($config->ab === null) {
            return null;
        }

        if ($config->ab->loadABSpecs !== '') {
            try {
                $config->ab->abSpecStore->save($config->ab->loadABSpecs);
            } catch (\Throwable $throwable) {
                $config->logger->error(
                    'ab snapshot bootstrap save failed',
                    ['error' => $throwable->getMessage()]
                );
            }
        }

        return $this->refreshABCore(true);
    }

    /**
     * 获取已初始化的 A/B core。
     */
    private function requireABCore(): ABCore
    {
        if ($this->abCore === null) {
            throw new InvalidArgumentException('ab core not initialized');
        }

        return $this->abCore;
    }

    /**
     * 构造字典型用户属性事件。
     */
    private function createUserPropertyEvent(
        User $user,
        Properties $properties,
        string $type,
        string $method
    ): Event {
        $options = UserPropertyOptions::create();
        foreach ($properties->all() as $key => $value) {
            if ($method === 'increment' && !is_int($value) && !is_float($value)) {
                continue;
            }
            $options->{$method}($key, $value);
        }

        return $this->buildUserPropertyEvent($user, $options, $type);
    }

    /**
     * 构造用户属性事件。
     */
    private function buildUserPropertyEvent(User $user, UserPropertyOptions $options, string $type): Event
    {
        return Event::create($user->anonId(), $user->loginId(), Predefined::EVENT_USER_SET)
            ->withUserPropertyOptions($options)
            ->withProperties(Properties::create()->set(Predefined::USER_SET_TYPE, $type));
    }

    /**
     * 在启用曝光时发送 A/B 曝光事件。
     */
    private function trackABImpressionIfNeeded(User $user, ABResult $result): void
    {
        if ($result->disableImpress || $result->key === '') {
            return;
        }

        $this->track(ABImpressionFactory::create($user, $result));
    }

    /**
     * 回调通知埋点失败。
     */
    private function notifyTrackFailure(string $body, ?\Throwable $error, ?int $statusCode): void
    {
        if ($this->config->onTrackFailHandler === null) {
            return;
        }

        try {
            /** @var list<array<string, mixed>> $events */
            $events = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $this->config->logger->error(
                'track fail handler payload decode failed',
                ['error' => $exception->getMessage()]
            );
            $events = [];
        }

        ($this->config->onTrackFailHandler)($events, $error, $statusCode);
    }

    /**
     * 将单条事件消息放入待发送队列。
     */
    private function enqueueTrackMessage(string $message): void
    {
        $this->pendingMessages[] = $message;
        $this->pendingBodySize += strlen($message);

        if (count($this->pendingMessages) >= self::MAX_BATCH_SIZE || $this->pendingBodySize >= self::MAX_HTTP_BODY_SIZE) {
            $this->flushPendingTrackMessages();
        }
    }

    /**
     * 在队列 flush 间隔到期时发送积压事件。
     */
    private function flushPendingTrackMessagesIfDue(): void
    {
        if ($this->config->flushIntervalMs <= 0 || $this->lastTrackFlushAtMs === null) {
            return;
        }

        if ($this->nowMs() - $this->lastTrackFlushAtMs >= $this->config->flushIntervalMs) {
            $this->flushPendingTrackMessages();
        }
    }

    /**
     * 将待发送事件批量刷出。
     */
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

    /**
     * 将数组或属性对象统一转成字典属性对象。
     *
     * @param array<string, mixed>|Properties $properties
     */
    private function normalizeProperties(array|Properties $properties): Properties
    {
        if ($properties instanceof Properties) {
            return $properties;
        }

        return Properties::fromArray($properties);
    }

    /**
     * 将数组或属性对象统一转成列表属性对象。
     *
     * @param array<string, mixed>|ListProperties $properties
     */
    private function normalizeListProperties(array|ListProperties $properties): ListProperties
    {
        if ($properties instanceof ListProperties) {
            return $properties;
        }

        $normalized = ListProperties::create();
        foreach ($properties as $key => $value) {
            $normalized->set($key, is_array($value) ? array_values($value) : [$value]);
        }

        return $normalized;
    }

    /**
     * 在下次求值前按需刷新远程 meta。
     */
    private function ensureABCoreFresh(): void
    {
        if ($this->config->ab === null) {
            return;
        }

        $this->refreshABCore(false);
    }

    /**
     * 刷新 A/B core。
     */
    private function refreshABCore(bool $forceInitialize): ?ABCore
    {
        try {
            if ($this->config->ab === null) {
                return $this->abCore;
            }

            $metadata = $this->config->ab->abSpecStore->metadata();
            if ($metadata->updatedAtMs === null) {
                if ($this->abCore !== null) {
                    return $this->abCore;
                }
                return null;
            }

            if ($this->abCore !== null && $this->lastMetaLoadAtMs === $metadata->updatedAtMs) {
                return $this->abCore;
            }

            $snapshot = $this->config->ab->abSpecStore->load();
            if ($snapshot === null || $snapshot === '') {
                $this->abCore = null;
                return null;
            }

            $storage = StorageFactory::fromJson($snapshot);
            $this->lastMetaLoadAtMs = $metadata->updatedAtMs;

            if ($this->abCore === null) {
                $this->abCore = new ABCore($storage, $this->stickyHandler);
                return $this->abCore;
            }

            $this->abCore->replaceStorage($storage);
            return $this->abCore;
        } catch (\Throwable) {
            $this->config->logger->error(
                'ab snapshot reload failed',
                [
                    'source_token' => $this->sourceToken,
                    'force_initialize' => $forceInitialize,
                ]
            );
            $this->abCore = null;
            return null;
        }
    }

    /**
     * 当前时间戳（毫秒）。
     */
    private function nowMs(): int
    {
        return (int) floor(microtime(true) * 1000);
    }

    /**
     * 校验 endpoint 格式。
     */
    private static function validateEndpoint(string $endpoint): void
    {
        $parts = parse_url($endpoint);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new InvalidArgumentException('endpoint is invalid');
        }

        $scheme = $parts['scheme'];
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new InvalidArgumentException('scheme must be http or https');
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}
