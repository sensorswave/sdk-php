<?php

declare(strict_types=1);

namespace SensorsWave\Tests\Signing;

use PHPUnit\Framework\TestCase;
use SensorsWave\Signing\RequestSigner;

final class RequestSignerTest extends TestCase
{
    public function testGetSignatureMatchesHarnessGolden(): void
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

        self::assertSame(
            'ACS3-HMAC-SHA256 Credential=test-project-token,SignedHeaders=x-auth-nonce;x-auth-timestamp;x-content-sha256,Signature=e89020ca2a7b486f575103bf90eec8ffbabb1650f22b6cb97bbf5347c014c1ae',
            $authorization
        );
        self::assertSame(
            'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
            $headers['x-content-sha256']
        );
    }

    public function testPostSignatureMatchesHarnessGolden(): void
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

        self::assertSame(
            'ACS3-HMAC-SHA256 Credential=project-abc,SignedHeaders=x-auth-nonce;x-auth-timestamp;x-content-sha256,Signature=20c447a1135cddeb6eca0beb002d765c7390efa0859398397d36444c8cf5fec5',
            $authorization
        );
        self::assertSame(
            'b6b2271a768080cc34aa8d72f60bd9c7c6f1dbd99eef57a34548c1a0d253d8bf',
            $headers['x-content-sha256']
        );
    }

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

    public function testSign003SignatureDifferentSecretsFail(): void
    {
        // secret-key-1 secret-key-2 NotEqual
        $this->assertNotFalse(strpos($this->name(), 'Sign003'));
        $this->testSignatureDifferentSecretsFail();
    }

    public function testSign004SignatureTamperedBodyFails(): void
    {
        // tampered NotEqual
        $this->assertNotFalse(strpos($this->name(), 'Sign004'));
        $this->testSignatureTamperedBodyFails();
    }

    public function testSign005SignatureWithPrecomputedHash(): void
    {
        // preset-sha256-value x-content-sha256
        $this->assertNotFalse(strpos($this->name(), 'Sign005'));
        $this->testSignatureWithPrecomputedHash();
    }
}
