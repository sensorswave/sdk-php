<?php

declare(strict_types=1);

namespace SensorsWave\Worker;

use JsonException;
use SensorsWave\ABTesting\ABCore;
use SensorsWave\ABTesting\HttpSignatureMetaLoader;
use SensorsWave\Config\ABConfig;
use SensorsWave\Http\TransportInterface;
use RuntimeException;

/**
 * 远程 metadata 同步任务。
 */
final class SyncCommand
{
    public function __construct(
        private readonly string $endpoint,
        private readonly string $sourceToken,
        private readonly ABConfig $config,
        private readonly ?TransportInterface $transport = null,
    ) {
    }

    public function run(): int
    {
        if ($this->config->projectSecret === '') {
            return 1;
        }

        $endpoint = $this->config->metaEndpoint !== ''
            ? $this->normalizeEndpoint($this->config->metaEndpoint)
            : $this->normalizeEndpoint($this->endpoint);
        $uriPath = $this->normalizeUriPath($this->config->metaUriPath, '/ab/all4eval');

        try {
            $loader = new HttpSignatureMetaLoader(
                endpoint: $endpoint,
                uriPath: $uriPath,
                sourceToken: $this->sourceToken,
                projectSecret: $this->config->projectSecret,
                transport: $this->transport,
            );
            $result = $loader->loadResult();
            if (!$result->update) {
                return 0;
            }

            $snapshot = json_encode($result->storage->toPayload(), JSON_THROW_ON_ERROR);
            $this->config->abSpecStore->save($snapshot);
            return 0;
        } catch (JsonException|RuntimeException|\InvalidArgumentException) {
            return 1;
        }
    }

    private function normalizeEndpoint(string $endpoint): string
    {
        $parts = parse_url($endpoint);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new \InvalidArgumentException('endpoint is invalid');
        }

        $scheme = $parts['scheme'];
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new \InvalidArgumentException('scheme must be http or https');
        }

        $normalized = $scheme . '://' . $parts['host'];
        if (isset($parts['port'])) {
            $normalized .= ':' . $parts['port'];
        }

        return $normalized;
    }

    private function normalizeUriPath(string $uriPath, string $defaultPath): string
    {
        if ($uriPath === '') {
            return $defaultPath;
        }

        return str_starts_with($uriPath, '/') ? $uriPath : '/' . $uriPath;
    }
}
