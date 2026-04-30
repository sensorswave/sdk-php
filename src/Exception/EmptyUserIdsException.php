<?php

declare(strict_types=1);

namespace SensorsWave\Exception;

use InvalidArgumentException;

/**
 * Thrown when both login_id and anon_id are empty.
 */
final class EmptyUserIdsException extends InvalidArgumentException
{
    public function __construct()
    {
        parent::__construct('login_id and anon_id are both empty');
    }
}
