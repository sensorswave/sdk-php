<?php

declare(strict_types=1);

namespace SensorsWave\ABTesting;

use JsonException;
use RuntimeException;
use SensorsWave\Http\HttpClient;
use SensorsWave\Http\Request;
use SensorsWave\Http\TransportInterface;
use SensorsWave\Signing\RequestSigner;
use SensorsWave\Support\SDKInfo;

/**
 * 基于 ACS3-HMAC-SHA256 的远程元数据加载器。
 */
final class HttpSignatureMetaLoader
{
    private readonly TransportInterface $transport;

    public function __construct(
        private readonly string $endpoint,
        private readonly string $uriPath,
        private readonly string $sourceToken,
        private readonly string $projectSecret,
        ?TransportInterface $transport = null,
    ) {
        $this->transport = $transport ?? new HttpClient();
    }

    /**
     * 拉取并解析远程 A/B 元数据快照。
     */
    public function load(): Storage
    {
        [$requestUri, $queryString] = $this->splitUriPath($this->uriPath);

        $headers = [
            'Content-Type' => 'application/json',
            'SourceToken' => $this->sourceToken,
            'X-SDK' => SDKInfo::TYPE,
            'X-SDK-Version' => SDKInfo::VERSION,
        ];

        RequestSigner::sign(
            'GET',
            $requestUri,
            $queryString,
            $headers,
            '',
            $this->sourceToken,
            $this->projectSecret
        );

        $response = $this->transport->send(
            new Request(
                'GET',
                rtrim($this->endpoint, '/') . $this->uriPath,
                $headers
            )
        );

        if ($response->statusCode !== 200) {
            throw new RuntimeException(sprintf('load meta failed, httpcode: %d', $response->statusCode));
        }

        try {
            return StorageFactory::fromJson($response->body);
        } catch (JsonException $exception) {
            throw new RuntimeException('unmarshal failed: ' . $exception->getMessage(), 0, $exception);
        }
    }

    /**
     * 拆分签名用的 path 与 query。
     *
     * @return array{0: string, 1: string}
     */
    private function splitUriPath(string $uriPath): array
    {
        $parts = parse_url($uriPath);
        if ($parts === false) {
            return [$uriPath, ''];
        }

        return [
            (string) ($parts['path'] ?? $uriPath),
            (string) ($parts['query'] ?? ''),
        ];
    }
}
