<?php

declare(strict_types=1);

namespace SensorsWave\Exception;

use InvalidArgumentException;

/**
 * 当 login_id 与 anon_id 同时为空时抛出。
 */
final class EmptyUserIdsException extends InvalidArgumentException
{
    public function __construct()
    {
        parent::__construct('login_id and anon_id are both empty');
    }
}
