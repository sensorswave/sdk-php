<?php

declare(strict_types=1);

namespace SensorsWave\Tracking;

use SensorsWave\Model\Event;
use SensorsWave\Model\Properties;
use SensorsWave\Model\User;
use SensorsWave\Model\UserPropertyOptions;

/**
 * Factory for user-property events.
 */
final class UserPropertyEventFactory
{
    /**
     * Build a profile set event.
     */
    public static function profileSet(User $user, Properties $properties): Event
    {
        $options = UserPropertyOptions::create();
        foreach ($properties->all() as $key => $value) {
            $options->set($key, $value);
        }

        return Event::create($user->anonId(), $user->loginId(), Predefined::EVENT_USER_SET)
            ->withUserPropertyOptions($options);
    }
}
