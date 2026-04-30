<?php

declare(strict_types=1);

namespace SensorsWave\Support;

/**
 * Hashing helpers used by the signer.
 */
final class Hash
{
    /**
     * Compute the SHA-256 hex digest.
     */
    public static function sha256Hex(string $data): string
    {
        return hash('sha256', $data);
    }

    /**
     * Compute the HMAC-SHA256 hex digest.
     */
    public static function hmacSha256Hex(string $key, string $data): string
    {
        return hash_hmac('sha256', $data, $key);
    }
}
