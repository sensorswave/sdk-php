<?php

declare(strict_types=1);

namespace SensorsWave\Support;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Normalizes nested property values into the canonical wire form.
 */
final class PropertyValueNormalizer
{
    /**
     * Normalize a single property value.
     */
    public static function normalize(mixed $value): mixed
    {
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value)
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d\\TH:i:s.v\\Z');
        }

        if (is_array($value)) {
            $normalized = [];
            foreach ($value as $key => $item) {
                $normalized[$key] = self::normalize($item);
            }

            return $normalized;
        }

        return $value;
    }
}
