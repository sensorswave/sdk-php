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
    public const USER_SET_TYPE_SET_ONCE = 'user_set_once';
    public const USER_SET_TYPE_INCREMENT = 'user_increment';
    public const USER_SET_TYPE_APPEND = 'user_append';
    public const USER_SET_TYPE_UNION = 'user_union';
    public const USER_SET_TYPE_UNSET = 'user_unset';
    public const USER_SET_TYPE_DELETE = 'user_delete';

    private function __construct()
    {
    }
}
