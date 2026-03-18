<?php

declare(strict_types=1);

namespace SensorsWave\Tracking;

/**
 * 跟踪事件与属性常量。
 */
final class Predefined
{
    public const EVENT_IDENTIFY = '$Identify';
    public const EVENT_USER_SET = '$UserSet';

    public const USER_SET_TYPE = '$user_set_type';
    public const USER_SET_TYPE_SET = 'user_set';

    private function __construct()
    {
    }
}
