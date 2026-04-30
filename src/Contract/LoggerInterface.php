<?php

declare(strict_types=1);

namespace SensorsWave\Contract;

/**
 * SDK logging interface.
 */
interface LoggerInterface
{
    /**
     * Emit a debug log.
     */
    public function debug(string $message, mixed ...$context): void;

    /**
     * Emit an info log.
     */
    public function info(string $message, mixed ...$context): void;

    /**
     * Emit a warning log.
     */
    public function warn(string $message, mixed ...$context): void;

    /**
     * Emit an error log.
     */
    public function error(string $message, mixed ...$context): void;
}
