<?php

declare(strict_types=1);

namespace SensorsWave\Model;

/**
 * Unified user identity.
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
     * Return the A/B user properties.
     */
    public function abUserProperties(): Properties
    {
        return $this->abUserProperties;
    }

    /**
     * Return a copy with the given A/B user property added.
     */
    public function withAbUserProperty(string $key, mixed $value): self
    {
        $properties = Properties::fromArray($this->abUserProperties->all())
            ->set($key, $value);

        return new self($this->anonId, $this->loginId, $properties);
    }

    /**
     * Return a copy with the given A/B user properties merged in.
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
