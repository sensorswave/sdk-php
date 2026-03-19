<?php

declare(strict_types=1);

namespace SensorsWave\Contract;

/**
 * Sticky 结果持久化接口。
 */
interface StickyHandlerInterface
{
    /**
     * 读取 sticky 结果。
     */
    public function getStickyResult(string $key): ?string;

    /**
     * 写入 sticky 结果。
     */
    public function setStickyResult(string $key, string $result): void;
}
