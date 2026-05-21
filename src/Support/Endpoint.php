<?php

declare(strict_types=1);

namespace SensorsWave\Support;

use InvalidArgumentException;

/**
 * Endpoint normalization helpers shared by client, worker, and HTTP pool code.
 */
final class Endpoint
{
    public static function normalizeEndpoint(string $endpoint): string
    {
        $parts = parse_url($endpoint);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new InvalidArgumentException('endpoint is invalid');
        }

        $scheme = $parts['scheme'];
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new InvalidArgumentException('scheme must be http or https');
        }

        $normalized = $scheme . '://' . $parts['host'];
        if (isset($parts['port'])) {
            $normalized .= ':' . $parts['port'];
        }

        return $normalized;
    }

    public static function normalizeUriPath(string $uriPath, string $defaultPath): string
    {
        if ($uriPath === '') {
            return $defaultPath;
        }

        return str_starts_with($uriPath, '/') ? $uriPath : '/' . $uriPath;
    }

    public static function originKey(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return $url;
        }

        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        return $parts['scheme'] . '://' . $parts['host'] . $port;
    }
}
