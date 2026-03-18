<?php

declare(strict_types=1);

namespace SensorsWave\Signing;

use SensorsWave\Support\Hash;

/**
 * ACS3-HMAC-SHA256 请求签名器。
 */
final class RequestSigner
{
    public const ALGORITHM = 'ACS3-HMAC-SHA256';

    /**
     * 为请求生成签名并写回必要请求头。
     *
     * @param array<string, string> $headers
     */
    public static function sign(
        string $method,
        string $uri,
        string $queryString,
        array &$headers,
        string $body,
        string $sourceToken,
        string $projectSecret
    ): string {
        $signHeaders = [];
        foreach ($headers as $key => $value) {
            $signHeaders[strtolower($key)] = $value;
        }

        $hashedPayload = Hash::sha256Hex($body);
        $signHeaders['x-content-sha256'] = $hashedPayload;

        if (!isset($signHeaders['x-auth-timestamp'])) {
            $signHeaders['x-auth-timestamp'] = (string) ((int) floor(microtime(true) * 1000));
        }

        if (!isset($signHeaders['x-auth-nonce'])) {
            $signHeaders['x-auth-nonce'] = (string) hrtime(true);
        }

        $canonicalRequest = CanonicalRequestBuilder::build(
            $method,
            $uri,
            $queryString,
            $signHeaders,
            $hashedPayload
        );

        $stringToSign = self::ALGORITHM . "\n" . Hash::sha256Hex($canonicalRequest);
        $signature = Hash::hmacSha256Hex($projectSecret, $stringToSign);
        $signedHeaders = implode(';', CanonicalRequestBuilder::sortedHeaderKeys($signHeaders));

        $authorization = sprintf(
            '%s Credential=%s,SignedHeaders=%s,Signature=%s',
            self::ALGORITHM,
            $sourceToken,
            $signedHeaders,
            $signature
        );

        $headers['x-content-sha256'] = $signHeaders['x-content-sha256'];
        $headers['x-auth-timestamp'] = $signHeaders['x-auth-timestamp'];
        $headers['x-auth-nonce'] = $signHeaders['x-auth-nonce'];
        $headers['Authorization'] = $authorization;

        return $authorization;
    }
}
