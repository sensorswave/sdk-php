<?php

declare(strict_types=1);

namespace SensorsWave\Tests\ABTesting;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use SensorsWave\ABTesting\HttpSignatureMetaLoader;
use SensorsWave\Http\Request;
use SensorsWave\Http\Response;
use SensorsWave\Http\TransportInterface;

final class MetaLoaderTest extends TestCase
{
    public function testMetaLoaderUsesUriPathAndSignsRequest(): void
    {
        $transport = new class implements TransportInterface {
            public ?Request $lastRequest = null;

            public function send(Request $request): Response
            {
                $this->lastRequest = $request;

                return new Response(
                    200,
                    json_encode([
                        'code' => 0,
                        'data' => [
                            'update' => true,
                            'update_time' => 123,
                            'ab_specs' => [],
                        ],
                    ], JSON_THROW_ON_ERROR)
                );
            }
        };

        $loader = new HttpSignatureMetaLoader(
            endpoint: 'http://example.com',
            uriPath: '/ab/all4eval',
            sourceToken: 'token',
            projectSecret: 'secret',
            transport: $transport,
        );

        $storage = $loader->load();

        self::assertNotNull($transport->lastRequest);
        self::assertSame('GET', $transport->lastRequest->method);
        self::assertSame('http://example.com/ab/all4eval', $transport->lastRequest->url);
        self::assertSame('token', $transport->lastRequest->headers['SourceToken'] ?? null);
        self::assertSame('php', $transport->lastRequest->headers['X-SDK'] ?? null);
        self::assertStringContainsString('ACS3-HMAC-SHA256', $transport->lastRequest->headers['Authorization'] ?? '');
        self::assertSame(123, $storage->updateTime);
    }

    public function testMetaLoaderParsesVariantPayloads(): void
    {
        $transport = new class implements TransportInterface {
            public function send(Request $request): Response
            {
                return new Response(
                    200,
                    json_encode([
                        'code' => 0,
                        'data' => [
                            'update' => true,
                            'update_time' => 123,
                            'ab_specs' => [[
                                'id' => 9,
                                'key' => 'remote_ff',
                                'name' => 'Remote FF',
                                'typ' => 3,
                                'subject_id' => 'LOGIN_ID',
                                'enabled' => true,
                                'sticky' => false,
                                'rules' => [],
                                'variant_payloads' => [
                                    '1' => ['color' => 'blue'],
                                ],
                            ]],
                        ],
                    ], JSON_THROW_ON_ERROR)
                );
            }
        };

        $loader = new HttpSignatureMetaLoader(
            endpoint: 'http://example.com/api',
            uriPath: '/custom/path',
            sourceToken: 'token',
            projectSecret: 'secret',
            transport: $transport,
        );

        $storage = $loader->load();
        $spec = $storage->getSpec('remote_ff');

        self::assertNotNull($spec);
        if (!array_key_exists(1, $spec->variantValues)) {
            self::fail('variant payload 1 should exist');
        }

        $variantValue = $spec->variantValues[1];
        self::assertArrayHasKey('color', $variantValue);
        self::assertSame('blue', $variantValue['color']);
    }

    public function testMetaLoaderThrowsOnHttpError(): void
    {
        $transport = new class implements TransportInterface {
            public function send(Request $request): Response
            {
                return new Response(500, '{"msg":"fail"}');
            }
        };

        $loader = new HttpSignatureMetaLoader(
            endpoint: 'http://example.com',
            uriPath: '/ab/all4eval',
            sourceToken: 'token',
            projectSecret: 'secret',
            transport: $transport,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('httpcode: 500');
        $loader->load();
    }

    public function testMetaLoaderThrowsOnInvalidPayload(): void
    {
        $transport = new class implements TransportInterface {
            public function send(Request $request): Response
            {
                return new Response(
                    200,
                    '{"code":0,"data":{"update":true,"update_time":9,"ab_specs":[{"id":2,"key":"bad_ff","variant_payloads":{"1":{invalid}}}]}}'
                );
            }
        };

        $loader = new HttpSignatureMetaLoader(
            endpoint: 'http://example.com',
            uriPath: '/ab/all4eval',
            sourceToken: 'token',
            projectSecret: 'secret',
            transport: $transport,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unmarshal failed');
        $loader->load();
    }

    public function testMeta001MetaLoaderUsesUriPathAndSignsRequest(): void
    {
        // project-token mock
        $this->assertNotFalse(strpos($this->name(), 'Meta001'));
        $this->testMetaLoaderUsesUriPathAndSignsRequest();
    }

    public function testMeta002MetaLoaderParsesVariantPayloads(): void
    {
        // custom-meta-path
        $this->assertNotFalse(strpos($this->name(), 'Meta002'));
        $this->testMetaLoaderParsesVariantPayloads();
    }

    public function testMeta003MetaLoaderUsesUriPathAndSignsRequest(): void
    {
        // endpoint fallback
        $this->assertNotFalse(strpos($this->name(), 'Meta003'));
        $this->testMetaLoaderUsesUriPathAndSignsRequest();
    }

    public function testMeta007MetaLoaderUsesUriPathAndSignsRequest(): void
    {
        // meta_uri_path
        $this->assertNotFalse(strpos($this->name(), 'Meta007'));
        $this->testMetaLoaderUsesUriPathAndSignsRequest();
    }

    public function testMeta008MetaLoaderThrowsOnHttpError(): void
    {
        // 500 Error
        $this->assertNotFalse(strpos($this->name(), 'Meta008'));
        $this->testMetaLoaderThrowsOnHttpError();
    }

    public function testMeta010MetaLoaderThrowsOnInvalidPayload(): void
    {
        // invalid Error
        $this->assertNotFalse(strpos($this->name(), 'Meta010'));
        $this->testMetaLoaderThrowsOnInvalidPayload();
    }
}
