<?php

declare(strict_types=1);

namespace SensorsWave\Contract;

/**
 * SDK 日志接口。
 */
interface LoggerInterface
{
    /**
     * 输出调试日志。
     */
    public function debug(string $message, mixed ...$context): void;

    /**
     * 输出信息日志。
     */
    public function info(string $message, mixed ...$context): void;

    /**
     * 输出告警日志。
     */
    public function warn(string $message, mixed ...$context): void;

    /**
     * 输出错误日志。
     */
    public function error(string $message, mixed ...$context): void;
}
