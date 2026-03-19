<?php

declare(strict_types=1);

namespace SensorsWave\ABTesting\ExposureLogging;

use SensorsWave\ABTesting\ABResult;
use SensorsWave\Client\Client;
use SensorsWave\Model\Event;
use SensorsWave\Model\Properties;
use SensorsWave\Model\User;
use SensorsWave\Model\UserPropertyOptions;

/**
 * 曝光事件构造器。
 */
final class ABImpressionFactory
{
    /**
     * 创建曝光事件。
     */
    public static function create(User $user, ABResult $result): Event
    {
        $properties = Properties::create();
        $userProperties = UserPropertyOptions::create();

        if (in_array($result->type, [Client::AB_TYPE_GATE, Client::AB_TYPE_CONFIG], true)) {
            $eventName = '$FeatureImpress';
            $userKey = '$feature_' . $result->id;
            $properties->set('$feature_key', $result->key);
            if ($result->variantId !== null) {
                $properties->set('$feature_variant', $result->variantId);
                $userProperties->set($userKey, $result->variantId);
            } else {
                $userProperties->unset($userKey);
            }
        } else {
            $eventName = '$ExpImpress';
            $userKey = '$exp_' . $result->id;
            $properties->set('$exp_key', $result->key);
            if ($result->variantId !== null) {
                $properties->set('$exp_variant', $result->variantId);
                $userProperties->set($userKey, $result->variantId);
            } else {
                $userProperties->unset($userKey);
            }
        }

        return Event::create($user->anonId(), $user->loginId(), $eventName)
            ->withProperties($properties)
            ->withUserPropertyOptions($userProperties);
    }
}
