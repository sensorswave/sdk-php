<?php

declare(strict_types=1);

namespace SensorsWave\Exception;

use InvalidArgumentException;

/**
 * Thrown when the event name is empty.
 */
final class EventNameEmptyException extends InvalidArgumentException
{
    public function __construct()
    {
        parent::__construct('event name is empty');
    }
}
