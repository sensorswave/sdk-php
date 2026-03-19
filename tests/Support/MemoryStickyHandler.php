<?php

declare(strict_types=1);

namespace SensorsWave\Tests\Support;

use SensorsWave\Contract\StickyHandlerInterface;

final class MemoryStickyHandler implements StickyHandlerInterface
{
    /** @var array<string, string> */
    public array $data = [];

    public function getStickyResult(string $key): ?string
    {
        return $this->data[$key] ?? null;
    }

    public function setStickyResult(string $key, string $result): void
    {
        $this->data[$key] = $result;
    }
}
