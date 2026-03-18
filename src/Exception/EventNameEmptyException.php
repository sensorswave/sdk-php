<?php

declare(strict_types=1);

namespace SensorsWave\Exception;

use InvalidArgumentException;

/**
 * 当事件名为空时抛出。
 */
final class EventNameEmptyException extends InvalidArgumentException
{
    public function __construct()
    {
        parent::__construct('event name is empty');
    }
}
