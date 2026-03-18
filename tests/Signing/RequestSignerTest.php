<?php

declare(strict_types=1);

namespace SensorsWave\Tests\Signing;

use PHPUnit\Framework\TestCase;
use SensorsWave\Signing\RequestSigner;

final class RequestSignerTest extends TestCase
{
    public function testSignatureGenerationAndVerification(): void
    {
        $headers = [
            'x-auth-timestamp' => '1736668800000',
            'x-auth-nonce' => 'test-nonce-12345',
        ];

        $authorization = RequestSigner::sign(
            'GET',
            '/ab/all4eval',
            '',
            $headers,
            '',
            'test-project-token',
            'test-secret-key'
        );

        self::assertStringContainsString('ACS3-HMAC-SHA256', $authorization);
        self::assertStringContainsString('Credential=test-project-token', $authorization);
        self::assertArrayHasKey('x-content-sha256', $headers);
        self::assertSame($authorization, $headers['Authorization']);

        $serverHeaders = [
            'x-auth-timestamp' => $headers['x-auth-timestamp'],
            'x-auth-nonce' => $headers['x-auth-nonce'],
            'x-content-sha256' => $headers['x-content-sha256'],
        ];

        $serverAuthorization = RequestSigner::sign(
            'GET',
            '/ab/all4eval',
            '',
            $serverHeaders,
            '',
            'test-project-token',
            'test-secret-key'
        );

        self::assertSame($authorization, $serverAuthorization);
    }

    public function testSignatureWithBody(): void
    {
        $headers = [
            'x-auth-timestamp' => '1736668800000',
            'x-auth-nonce' => 'nonce-abc123',
        ];

        $authorization = RequestSigner::sign(
            'POST',
            '/ab/data',
            'param1=value1&param2=value2',
            $headers,
            '{"key":"value","number":123}',
            'project-abc',
            'secret-xyz'
        );

        self::assertArrayHasKey('x-content-sha256', $headers);
        self::assertSame($authorization, $headers['Authorization']);

        $serverHeaders = [
            'x-auth-timestamp' => $headers['x-auth-timestamp'],
            'x-auth-nonce' => $headers['x-auth-nonce'],
            'x-content-sha256' => $headers['x-content-sha256'],
        ];

        $serverAuthorization = RequestSigner::sign(
            'POST',
            '/ab/data',
            'param1=value1&param2=value2',
            $serverHeaders,
            '{"key":"value","number":123}',
            'project-abc',
            'secret-xyz'
        );

        self::assertSame($authorization, $serverAuthorization);
    }

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
