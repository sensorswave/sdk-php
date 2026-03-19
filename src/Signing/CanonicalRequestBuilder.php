<?php

declare(strict_types=1);

namespace SensorsWave\Signing;

/**
 * Canonical request 构造器。
 */
final class CanonicalRequestBuilder
{
    /**
     * 构造 canonical request 字符串。
     *
     * @param array<string, string> $headers
     */
    public static function build(
        string $method,
        string $uri,
        string $queryString,
        array $headers,
        string $hashedPayload
    ): string {
        $sortedKeys = self::sortedHeaderKeys($headers);
        $lines = [
            $method,
            $uri,
            $queryString,
        ];

        foreach ($sortedKeys as $key) {
            $lines[] = strtolower($key) . ':' . trim($headers[$key]);
        }

        return implode("\n", [
            implode("\n", $lines),
            '',
            implode(';', $sortedKeys),
            $hashedPayload,
        ]);
    }

    /**
     * 返回排序后的 header 名称。
     *
     * @param array<string, string> $headers
     *
     * @return list<string>
     */
    public static function sortedHeaderKeys(array $headers): array
    {
        $keys = array_map(
            static fn (string $key): string => strtolower($key),
            array_keys($headers)
        );
        sort($keys);

        return $keys;
    }
}
