<?php

declare(strict_types=1);

namespace SensorsWave\Model;

use JsonSerializable;
use SensorsWave\Exception\EmptyUserIdsException;
use SensorsWave\Exception\EventNameEmptyException;
use SensorsWave\Support\SDKInfo;
use SensorsWave\Support\Uuid;

/**
 * 单条事件对象。
 */
final class Event implements JsonSerializable
{
    private function __construct(
        private string $anonId,
        private string $loginId,
        private int $time,
        private string $traceId,
        private string $event,
        private Properties $properties,
        private UserPropertyOptions $userProperties,
    ) {
    }

    /**
     * 创建新的事件对象。
     */
    public static function create(string $anonId, string $loginId, string $event): self
    {
        return new self(
            $anonId,
            $loginId,
            (int) floor(microtime(true) * 1000),
            Uuid::v4(),
            $event,
            Properties::create(),
            UserPropertyOptions::create(),
        );
    }

    /**
     * 覆盖 trace ID。
     */
    public function withTraceId(string $traceId): self
    {
        $clone = clone $this;
        $clone->traceId = $traceId;

        return $clone;
    }

    /**
     * 覆盖事件时间。
     */
    public function withTime(int $time): self
    {
        $clone = clone $this;
        $clone->time = $time;

        return $clone;
    }

    /**
     * 覆盖事件属性。
     */
    public function withProperties(Properties $properties): self
    {
        $clone = clone $this;
        $clone->properties = $properties;

        return $clone;
    }

    /**
     * 覆盖用户属性操作。
     */
    public function withUserPropertyOptions(UserPropertyOptions $options): self
    {
        $clone = clone $this;
        $clone->userProperties = $options;

        return $clone;
    }

    /**
     * 归一化事件内容并注入默认属性。
     */
    public function normalize(): void
    {
        if ($this->anonId === '' && $this->loginId === '') {
            throw new EmptyUserIdsException();
        }

        if ($this->event === '') {
            throw new EventNameEmptyException();
        }

        if ($this->traceId === '') {
            $this->traceId = Uuid::v4();
        }

        if ($this->time === 0) {
            $this->time = (int) floor(microtime(true) * 1000);
        }

        if (!$this->properties->has('$lib')) {
            $this->properties->set('$lib', SDKInfo::TYPE);
        }

        if (!$this->properties->has('$lib_version')) {
            $this->properties->set('$lib_version', SDKInfo::VERSION);
        }
    }

    /**
     * 返回事件时间。
     */
    public function time(): int
    {
        return $this->time;
    }

    /**
     * 返回 trace ID。
     */
    public function traceId(): string
    {
        return $this->traceId;
    }

    /**
     * 返回事件属性。
     */
    public function properties(): Properties
    {
        return $this->properties;
    }

    /**
     * 返回匿名 ID。
     */
    public function anonId(): string
    {
        return $this->anonId;
    }

    /**
     * 返回登录 ID。
     */
    public function loginId(): string
    {
        return $this->loginId;
    }

    /**
     * 返回用户属性操作集合。
     */
    public function userProperties(): UserPropertyOptions
    {
        return $this->userProperties;
    }

    /**
     * 返回事件名。
     */
    public function event(): string
    {
        return $this->event;
    }

    /**
     * 导出 JSON。
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'anon_id' => $this->anonId,
            'login_id' => $this->loginId,
            'time' => $this->time,
            'trace_id' => $this->traceId,
            'event' => $this->event,
            'properties' => $this->properties->all(),
            'user_properties' => $this->userProperties->all(),
        ];
    }
}
