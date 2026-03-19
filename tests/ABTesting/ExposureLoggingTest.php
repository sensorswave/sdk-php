<?php

declare(strict_types=1);

namespace SensorsWave\Tests\ABTesting;

use PHPUnit\Framework\TestCase;
use SensorsWave\ABTesting\ABResult;
use SensorsWave\ABTesting\ExposureLogging\ABImpressionFactory;
use SensorsWave\Client\Client;
use SensorsWave\Model\User;

final class ExposureLoggingTest extends TestCase
{
    public function testFeatureImpressionPayload(): void
    {
        $event = ABImpressionFactory::create(
            new User('anon', 'login'),
            new ABResult(id: 12, key: 'feat_key', type: Client::AB_TYPE_GATE, variantId: 'on')
        );

        self::assertSame('$FeatureImpress', $event->event());
        self::assertSame('feat_key', $event->properties()->get('$feature_key'));
        self::assertSame('on', $event->properties()->get('$feature_variant'));
        self::assertSame('on', $event->userProperties()->group('$set')['$feature_12']);
        self::assertNull($event->properties()->get('$exp_key'));
    }

    public function testExperimentImpressionPayload(): void
    {
        $event = ABImpressionFactory::create(
            new User('anon', 'login'),
            new ABResult(id: 99, key: 'exp_key', type: Client::AB_TYPE_EXPERIMENT, variantId: 'B')
        );

        self::assertSame('$ExpImpress', $event->event());
        self::assertSame('exp_key', $event->properties()->get('$exp_key'));
        self::assertSame('B', $event->properties()->get('$exp_variant'));
        self::assertSame('B', $event->userProperties()->group('$set')['$exp_99']);
        self::assertNull($event->properties()->get('$feature_key'));
    }

    public function testFeatureImpressionUnsetsPropertyWhenVariantMissing(): void
    {
        $event = ABImpressionFactory::create(
            new User('anon', 'login'),
            new ABResult(id: 7, key: 'feat_key', type: Client::AB_TYPE_CONFIG)
        );

        self::assertArrayHasKey('$feature_7', $event->userProperties()->group('$unset'));
        self::assertNull($event->properties()->get('$feature_variant'));
    }
}
