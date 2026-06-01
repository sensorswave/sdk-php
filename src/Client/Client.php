<?php

declare(strict_types=1);

namespace SensorsWave\Client;

use InvalidArgumentException;
use JsonException;
use SensorsWave\ABTesting\ABCore;
use SensorsWave\ABTesting\ABResult;
use SensorsWave\ABTesting\ExposureLogging\ABImpressionFactory;
use SensorsWave\ABTesting\StorageFactory;
use SensorsWave\Config\Config;
use SensorsWave\Exception\EmptyUserIdsException;
use SensorsWave\Exception\IdentifyRequiresBothIdsException;
use SensorsWave\Model\Event;
use SensorsWave\Model\ListProperties;
use SensorsWave\Model\Properties;
use SensorsWave\Model\User;
use SensorsWave\Model\UserPropertyOptions;
use SensorsWave\Support\Endpoint;
use SensorsWave\Tracking\EventSerializer;
use SensorsWave\Tracking\Predefined;
use SensorsWave\Tracking\UserPropertyEventFactory;

/**
 * PHP-style SDK client.
 */
final class Client
{
    public const AB_TYPE_GATE = 1;
    public const AB_TYPE_CONFIG = 2;
    public const AB_TYPE_EXPERIMENT = 3;

    private bool $closed = false;
    private ?ABCore $abCore = null;
    private readonly ?\SensorsWave\Contract\StickyHandlerInterface $stickyHandler;

    private function __construct(
        string $endpoint,
        private readonly string $sourceToken,
        private readonly Config $config,
    ) {
        self::validateEndpoint($endpoint);
        $this->stickyHandler = $config->ab?->stickyHandler;
        $this->abCore = $this->refreshABCore(true);
        register_shutdown_function([$this, 'close']);
    }

    /**
     * Create a new client instance.
     */
    public static function create(string $endpoint, string $sourceToken, ?Config $config = null): self
    {
        return new self($endpoint, $sourceToken, $config ?? new Config());
    }

    /**
     * Close the client.
     */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
    }

    /**
     * Lifecycle-compatible no-op.
     *
     * PHP request-path tracking writes every event directly into EventQueue;
     * HTTP delivery is handled by SendCommand.
     *
     * @deprecated Use close() for the client lifecycle. Remote delivery is handled by SendCommand.
     */
    public function flush(): void
    {
        if ($this->closed) {
            return;
        }

        // Track writes directly to EventQueue; remote delivery is handled by SendCommand.
    }

    /**
     * Send an Identify event.
     */
    public function identify(User $user): void
    {
        if ($user->anonId() === '' || $user->loginId() === '') {
            throw new IdentifyRequiresBothIdsException();
        }

        $this->track(Event::create($user->anonId(), $user->loginId(), Predefined::EVENT_IDENTIFY));
    }

    /**
     * Send a custom tracking event.
     *
     * Complex property input conventions (server-side limits; the SDK
     * does not validate, exceeding any of these may be silently
     * truncated/dropped by the server):
     *  - properties array: at most 256 caller-supplied keys per event
     *  - any string value: at most 1024 UTF-8 bytes
     *  - OBJECT_ARRAY (list whose elements are associative arrays):
     *    at most 100 elements
     *
     * See README "Complex Property Input Conventions" for details.
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
     * Send a fully-built event.
     *
     * The Event's properties and user_properties are subject to the same
     * conventions as trackEvent (see trackEvent doc for details).
     */
    public function track(Event $event): void
    {
        if ($this->closed) {
            throw new InvalidArgumentException('the client was already closed');
        }

        $event->normalize();
        $this->enqueueTrackMessage(EventSerializer::serialize($event));
    }

    /**
     * Send a profile set event. Object and Object Array values are
     * forwarded to the server as-is.
     *
     * Complex property input conventions (server-side limits; the SDK
     * does not validate):
     *  - any string value: at most 1024 UTF-8 bytes
     *  - OBJECT_ARRAY (list whose elements are associative arrays):
     *    at most 100 elements
     *
     * See README "Complex Property Input Conventions" for details.
     */
    public function profileSet(User $user, array|Properties $properties): void
    {
        $this->validateUser($user);
        $this->track(UserPropertyEventFactory::profileSet($user, $this->normalizeProperties($properties)));
    }

    /**
     * Send a profile set-once event. Object and Object Array values are
     * forwarded to the server as-is.
     *
     * Complex property input conventions (server-side limits; the SDK
     * does not validate):
     *  - any string value: at most 1024 UTF-8 bytes
     *  - OBJECT_ARRAY (list whose elements are associative arrays):
     *    at most 100 elements
     *
     * See README "Complex Property Input Conventions" for details.
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
     * Send a profile increment event.
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
     * Send a profile append event. Each value must be a list of scalars.
     *
     * Object (associative array) and Object Array (indexed array of
     * associative arrays) values are **not accepted**: the SDK does not
     * reject them, but the server will infer them as OBJECT_ARRAY, which
     * contradicts list semantics. Only pass scalar lists.
     *
     * Complex property input conventions (server-side limits; the SDK
     * does not validate):
     *  - any string value: at most 1024 UTF-8 bytes
     *
     * See README "Complex Property Input Conventions" for details.
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
     * Send a profile union event. Each value must be a list of scalars
     * (auto-deduplicated).
     *
     * Object (associative array) and Object Array (indexed array of
     * associative arrays) values are **not accepted**: the SDK does not
     * reject them, but the server will infer them as OBJECT_ARRAY, which
     * contradicts list semantics. Only pass scalar lists.
     *
     * Complex property input conventions (server-side limits; the SDK
     * does not validate):
     *  - any string value: at most 1024 UTF-8 bytes
     *
     * See README "Complex Property Input Conventions" for details.
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
     * Send a profile unset event.
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
     * Send a profile delete event.
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
     * Evaluate a feature gate.
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
     * Fetch a feature config.
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
     * Fetch the experiment result.
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
     * Evaluate every spec in the current metadata snapshot.
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
     * Export the current A/B metadata snapshot.
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
        try {
            $this->config->eventQueue->enqueue([$message]);
        } catch (\Throwable $throwable) {
            $this->config->logger->error(
                'event queue enqueue failed',
                ['error' => $throwable->getMessage()]
            );
            $this->notifyTrackFailure('[' . $message . ']', $throwable, null);
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
     *
     * PHP-FPM 模型：每个请求首次调用时加载，后续直接使用内存中的实例。
     * 优先使用 loadABSpecs（如果配置了），否则从 store 加载。
     */
    private function refreshABCore(bool $forceInitialize): ?ABCore
    {
        try {
            if ($this->config->ab === null) {
                return $this->abCore;
            }

            if ($this->abCore !== null) {
                return $this->abCore;
            }

            $snapshot = $this->config->ab->loadABSpecs !== ''
                ? $this->config->ab->loadABSpecs
                : $this->config->ab->abSpecStore->load();

            if ($snapshot === null || $snapshot === '') {
                return null;
            }

            $storage = StorageFactory::fromJson($snapshot);
            $this->abCore = new ABCore($storage, $this->stickyHandler);
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
     * 校验 endpoint 格式。
     */
    private static function validateEndpoint(string $endpoint): void
    {
        Endpoint::normalizeEndpoint($endpoint);
    }

    public function __destruct()
    {
        $this->close();
    }
}
