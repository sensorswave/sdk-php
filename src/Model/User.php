<?php

declare(strict_types=1);

namespace SensorsWave\Model;

/**
 * 统一用户标识。
 */
final class User
{
    private Properties $abUserProperties;

    public function __construct(
        private string $anonId = '',
        private string $loginId = '',
        array|Properties|null $abUserProperties = null,
    ) {
        $this->abUserProperties = match (true) {
            $abUserProperties instanceof Properties => $abUserProperties,
            is_array($abUserProperties) => Properties::fromArray($abUserProperties),
            default => Properties::create(),
        };
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
     * 返回 A/B 用户属性。
     */
    public function abUserProperties(): Properties
    {
        return $this->abUserProperties;
    }

    /**
     * 返回添加了单个 A/B 属性的新用户对象。
     */
    public function withAbUserProperty(string $key, mixed $value): self
    {
        $properties = Properties::fromArray($this->abUserProperties->all())
            ->set($key, $value);

        return new self($this->anonId, $this->loginId, $properties);
    }

    /**
     * 返回添加了多个 A/B 属性的新用户对象。
     */
    public function withAbUserProperties(array|Properties $properties): self
    {
        $normalizedProperties = is_array($properties)
            ? Properties::fromArray($properties)
            : $properties;

        $merged = Properties::fromArray($this->abUserProperties->all())
            ->merge($normalizedProperties);

        return new self($this->anonId, $this->loginId, $merged);
    }
}
