<?php

declare(strict_types=1);

namespace SensorsWave\Tracking;

use SensorsWave\Model\Event;
use SensorsWave\Model\Properties;
use SensorsWave\Model\User;
use SensorsWave\Model\UserPropertyOptions;

/**
 * 用户属性事件构造器。
 */
final class UserPropertyEventFactory
{
    /**
     * 创建 profile set 事件。
     */
    public static function profileSet(User $user, Properties $properties): Event
    {
        $options = UserPropertyOptions::create();
        foreach ($properties->all() as $key => $value) {
            $options->set($key, $value);
        }

        return Event::create($user->anonId(), $user->loginId(), Predefined::EVENT_USER_SET)
            ->withUserPropertyOptions($options)
            ->withProperties(
                Properties::create()->set(
                    Predefined::USER_SET_TYPE,
                    Predefined::USER_SET_TYPE_SET
                )
            );
    }
}
