<?php

declare(strict_types=1);

namespace SensorsWave\Support;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * 统一规范化非顶层属性值。
 */
final class PropertyValueNormalizer
{
    /**
     * 规范化单个属性值。
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
