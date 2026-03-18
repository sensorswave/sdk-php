<?php

declare(strict_types=1);

namespace SensorsWave\Exception;

use InvalidArgumentException;

/**
 * Identify 缺少双 ID 时抛出。
 */
final class IdentifyRequiresBothIdsException extends InvalidArgumentException
{
    public function __construct()
    {
        parent::__construct('Identify requires both login_id and anon_id to be non-empty');
    }
}
