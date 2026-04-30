<?php

declare(strict_types=1);

namespace SensorsWave\Model;

use JsonSerializable;
use SensorsWave\Exception\EmptyUserIdsException;
use SensorsWave\Exception\EventNameEmptyException;
use SensorsWave\Support\SDKInfo;
use SensorsWave\Support\Uuid;

/**
 * Single event payload.
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
     * Create a new event.
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
     * Return a copy with the given trace ID.
     */
    public function withTraceId(string $traceId): self
    {
        $clone = clone $this;
        $clone->traceId = $traceId;

        return $clone;
    }

    /**
     * Return a copy with the given event time.
     */
    public function withTime(int $time): self
    {
        $clone = clone $this;
        $clone->time = $time;

        return $clone;
    }

    /**
     * Return a copy with the given properties.
     */
    public function withProperties(Properties $properties): self
    {
        $clone = clone $this;
        $clone->properties = $properties;

        return $clone;
    }

    /**
     * Return a copy with the given user-property options.
     */
    public function withUserPropertyOptions(UserPropertyOptions $options): self
    {
        $clone = clone $this;
        $clone->userProperties = $options;

        return $clone;
    }

    /**
     * Normalize the event payload and inject default properties.
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

        $this->properties->normalizeInPlace();
        $this->userProperties->normalizeInPlace();
    }

    /**
     * Return the event time.
     */
    public function time(): int
    {
        return $this->time;
    }

    /**
     * Return the trace ID.
     */
    public function traceId(): string
    {
        return $this->traceId;
    }

    /**
     * Return the event properties.
     */
    public function properties(): Properties
    {
        return $this->properties;
    }

    /**
     * Return the anonymous ID.
     */
    public function anonId(): string
    {
        return $this->anonId;
    }

    /**
     * Return the login ID.
     */
    public function loginId(): string
    {
        return $this->loginId;
    }

    /**
     * Return the user-property options.
     */
    public function userProperties(): UserPropertyOptions
    {
        return $this->userProperties;
    }

    /**
     * Return the event name.
     */
    public function event(): string
    {
        return $this->event;
    }

    /**
     * Export as a JSON-encodable array.
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
            'user_properties' => $this->userProperties->all() ?: (object) [],
        ];
    }
}
