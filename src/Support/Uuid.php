<?php

declare(strict_types=1);

namespace SensorsWave\Support;

/**
 * UUID 生成工具。
 */
final class Uuid
{
    /**
     * 生成一个 RFC 4122 v4 UUID。
     */
    public static function v4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf(
            '%s%s-%s-%s-%s-%s%s%s',
            str_split(bin2hex($bytes), 4)
        );
    }
}
