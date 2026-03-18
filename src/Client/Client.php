<?php

declare(strict_types=1);

namespace SensorsWave\Client;

use InvalidArgumentException;
use SensorsWave\Config\Config;
use SensorsWave\Exception\EmptyUserIdsException;
use SensorsWave\Exception\IdentifyRequiresBothIdsException;
use SensorsWave\Http\HttpClient;
use SensorsWave\Http\Request;
use SensorsWave\Http\TransportInterface;
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
    private bool $closed = false;
    private readonly TransportInterface $transport;
    private readonly string $normalizedEndpoint;

    private function __construct(
        string $endpoint,
        private readonly string $sourceToken,
        private readonly Config $config,
    ) {
        $this->normalizedEndpoint = self::normalizeEndpoint($endpoint);
        $this->transport = $config->transport ?? new HttpClient();
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
        $this->closed = true;
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
    public function trackEvent(User $user, string $eventName, Properties $properties): void
    {
        $this->validateUser($user);

        $event = Event::create($user->anonId(), $user->loginId(), $eventName)
            ->withProperties(Properties::fromArray($properties->all()));

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
        $body = EventSerializer::serializeBatch([$event]);

        $this->transport->send(
            new Request(
                'POST',
                $this->normalizedEndpoint . $this->config->trackUriPath,
                [
                    'Content-Type' => 'application/json',
                    'SourceToken' => $this->sourceToken,
                ],
                $body
            )
        );
    }

    /**
     * 发送 profile set 事件。
     */
    public function profileSet(User $user, Properties $properties): void
    {
        $this->validateUser($user);
        $this->track(UserPropertyEventFactory::profileSet($user, $properties));
    }

    /**
     * 发送 profile set once 事件。
     */
    public function profileSetOnce(User $user, Properties $properties): void
    {
        $this->validateUser($user);
        $this->track(
            $this->createUserPropertyEvent($user, $properties, Predefined::USER_SET_TYPE_SET_ONCE, 'setOnce')
        );
    }

    /**
     * 发送 profile increment 事件。
     */
    public function profileIncrement(User $user, Properties $properties): void
    {
        $this->validateUser($user);
        $this->track(
            $this->createUserPropertyEvent($user, $properties, Predefined::USER_SET_TYPE_INCREMENT, 'increment')
        );
    }

    /**
     * 发送 profile append 事件。
     */
    public function profileAppend(User $user, ListProperties $properties): void
    {
        $this->validateUser($user);
        $options = UserPropertyOptions::create();
        foreach ($properties->all() as $key => $value) {
            $options->append($key, $value);
        }
        $this->track($this->buildUserPropertyEvent($user, $options, Predefined::USER_SET_TYPE_APPEND));
    }

    /**
     * 发送 profile union 事件。
     */
    public function profileUnion(User $user, ListProperties $properties): void
    {
        $this->validateUser($user);
        $options = UserPropertyOptions::create();
        foreach ($properties->all() as $key => $value) {
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
     * 校验用户标识。
     */
    private function validateUser(User $user): void
    {
        if ($user->anonId() === '' && $user->loginId() === '') {
            throw new EmptyUserIdsException();
        }
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
     * 归一化 endpoint，仅保留 scheme 与 host。
     */
    private static function normalizeEndpoint(string $endpoint): string
    {
        $parts = parse_url($endpoint);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new InvalidArgumentException('endpoint is invalid');
        }

        $scheme = $parts['scheme'];
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new InvalidArgumentException('scheme must be http or https');
        }

        $normalized = $scheme . '://' . $parts['host'];
        if (isset($parts['port'])) {
            $normalized .= ':' . $parts['port'];
        }

        return $normalized;
    }
}
