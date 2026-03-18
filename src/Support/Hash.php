<?php

declare(strict_types=1);

namespace SensorsWave\Support;

/**
 * 哈希与签名辅助方法。
 */
final class Hash
{
    /**
     * 计算 SHA-256 十六进制摘要。
     */
    public static function sha256Hex(string $data): string
    {
        return hash('sha256', $data);
    }

    /**
     * 计算 HMAC-SHA256 十六进制摘要。
     */
    public static function hmacSha256Hex(string $key, string $data): string
    {
        return hash_hmac('sha256', $data, $key);
    }
}
