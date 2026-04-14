<?php

declare(strict_types=1);

namespace SensorsWave\Support;

use SensorsWave\Contract\LoggerInterface;

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
        error_log('[sensorswave][warn] ' . $this->format($message, $context));
    }

    public function error(string $message, mixed ...$context): void
    {
        error_log('[sensorswave][error] ' . $this->format($message, $context));
    }

    private function format(string $message, array $context): string
    {
        if ($context === []) {
            return $message;
        }

        $encoded = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $message . ' ' . ($encoded !== false ? $encoded : '');
    }
}
