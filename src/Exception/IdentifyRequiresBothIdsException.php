<?php

declare(strict_types=1);

namespace SensorsWave\Exception;

use InvalidArgumentException;

/**
 * Thrown when identify() is called without both IDs being non-empty.
 */
final class IdentifyRequiresBothIdsException extends InvalidArgumentException
{
    public function __construct()
    {
        parent::__construct('Identify requires both login_id and anon_id to be non-empty');
    }
}
