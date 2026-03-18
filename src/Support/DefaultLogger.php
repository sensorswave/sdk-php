<?php

declare(strict_types=1);

namespace SensorsWave\Support;

use SensorsWave\Contract\LoggerInterface;

/**
 * 默认日志实现。
 */
final class DefaultLogger implements LoggerInterface
{
    public function debug(string $message, mixed ...$context): void
    {
    }

    public function info(string $message, mixed ...$context): void
    {
    }

    public function warn(string $message, mixed ...$context): void
    {
    }

    public function error(string $message, mixed ...$context): void
    {
    }
}
