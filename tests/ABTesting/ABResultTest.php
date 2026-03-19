<?php

declare(strict_types=1);

namespace SensorsWave\Tests\ABTesting;

use PHPUnit\Framework\TestCase;
use JsonException;
use SensorsWave\ABTesting\ABResult;

final class ABResultTest extends TestCase
{
    public function testGetSliceReturnsListPayloadOrFallback(): void
    {
        $result = new ABResult(
            variantParamValue: [
                'items' => ['a', 'b'],
                'settings' => ['enabled' => true],
            ]
        );

        self::assertSame(['a', 'b'], $result->getSlice('items', ['fallback']));
        self::assertSame(['fallback'], $result->getSlice('settings', ['fallback']));
        self::assertSame(['fallback'], $result->getSlice('missing', ['fallback']));
    }

    public function testGetMapReturnsMapPayloadOrFallback(): void
    {
        $result = new ABResult(
            variantParamValue: [
                'items' => ['a', 'b'],
                'settings' => ['enabled' => true],
            ]
        );

        self::assertSame(['enabled' => true], $result->getMap('settings', ['fallback' => false]));
        self::assertSame(['fallback' => false], $result->getMap('items', ['fallback' => false]));
        self::assertSame(['fallback' => false], $result->getMap('missing', ['fallback' => false]));
    }

    /**
     * @throws JsonException
     */
    public function testJsonPayloadExportsVariantParamValue(): void
    {
        $result = new ABResult(
            variantParamValue: [
                'color' => 'blue',
                'enabled' => true,
            ]
        );

        self::assertSame(
            '{"color":"blue","enabled":true}',
            $result->jsonPayload()
        );
    }
}
