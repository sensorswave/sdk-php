<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class BootstrapTest extends TestCase
{
    public function testComposerMetadataAndAutoloadAreAvailable(): void
    {
        $composerFile = dirname(__DIR__) . '/composer.json';
        self::assertFileExists($composerFile);

        $contents = file_get_contents($composerFile);
        self::assertNotFalse($contents);

        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('sensorswave/sdk-php', $decoded['name'] ?? null);

        $autoloadFile = dirname(__DIR__) . '/vendor/autoload.php';
        self::assertFileExists($autoloadFile);

        $loader = require $autoloadFile;
        self::assertInstanceOf(Composer\Autoload\ClassLoader::class, $loader);
    }
}
