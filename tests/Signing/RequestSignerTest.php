<?php

declare(strict_types=1);

namespace SensorsWave\Tests\Signing;

use PHPUnit\Framework\TestCase;
use SensorsWave\Signing\RequestSigner;

/**
 * RequestSignerTest — sign-003 / sign-004 / sign-005 三条 C 类（unit_test 类）签名测试。
 *
 * sign-001 / sign-002 的 A 类覆盖已迁移到
 * tests/Conformance/RequestSigningACS3Test（完全派生模式）。
 */
final class RequestSignerTest extends TestCase
{
    public function testSignatureDifferentSecretsFail(): void
    {
        $clientHeaders = [
            'x-auth-timestamp' => '1736668800000',
            'x-auth-nonce' => 'nonce-123',
        ];

        $clientAuthorization = RequestSigner::sign(
            'GET',
            '/api/test',
            '',
            $clientHeaders,
            '',
            'project-abc',
            'client-secret'
        );

        $serverHeaders = [
            'x-auth-timestamp' => $clientHeaders['x-auth-timestamp'],
            'x-auth-nonce' => $clientHeaders['x-auth-nonce'],
            'x-content-sha256' => $clientHeaders['x-content-sha256'],
        ];

        $serverAuthorization = RequestSigner::sign(
            'GET',
            '/api/test',
            '',
            $serverHeaders,
            '',
            'project-abc',
            'server-secret-different'
        );

        self::assertNotSame($clientAuthorization, $serverAuthorization);
    }

    public function testSignatureTamperedBodyFails(): void
    {
        $clientHeaders = [
            'x-auth-timestamp' => '1736668800000',
            'x-auth-nonce' => 'nonce-456',
        ];

        $clientAuthorization = RequestSigner::sign(
            'POST',
            '/api/data',
            '',
            $clientHeaders,
            '{"original":true}',
            'project-abc',
            'secret-xyz'
        );

        $serverHeaders = [
            'x-auth-timestamp' => $clientHeaders['x-auth-timestamp'],
            'x-auth-nonce' => $clientHeaders['x-auth-nonce'],
        ];

        $serverAuthorization = RequestSigner::sign(
            'POST',
            '/api/data',
            '',
            $serverHeaders,
            '{"original":false}',
            'project-abc',
            'secret-xyz'
        );

        self::assertNotSame($clientAuthorization, $serverAuthorization);
    }

    public function testSignatureWithPrecomputedHash(): void
    {
        $body = '{"test":"data"}';
        $bodyHash = hash('sha256', $body);

        $clientHeaders = [
            'x-auth-timestamp' => '1736668800000',
            'x-auth-nonce' => 'nonce-789',
            'x-content-sha256' => $bodyHash,
        ];

        $clientAuthorization = RequestSigner::sign(
            'POST',
            '/api/data',
            '',
            $clientHeaders,
            $body,
            'project-abc',
            'secret-xyz'
        );

        $serverHeaders = [
            'x-auth-timestamp' => $clientHeaders['x-auth-timestamp'],
            'x-auth-nonce' => $clientHeaders['x-auth-nonce'],
            'x-content-sha256' => $clientHeaders['x-content-sha256'],
        ];

        $serverAuthorization = RequestSigner::sign(
            'POST',
            '/api/data',
            '',
            $serverHeaders,
            $body,
            'project-abc',
            'secret-xyz'
        );

        self::assertSame($clientAuthorization, $serverAuthorization);
    }
}
