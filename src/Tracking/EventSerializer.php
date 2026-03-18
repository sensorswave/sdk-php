<?php

declare(strict_types=1);

namespace SensorsWave\Tracking;

use JsonException;
use SensorsWave\Model\Event;

/**
 * 事件序列化器。
 */
final class EventSerializer
{
    /**
     * 序列化单条事件。
     *
     * @throws JsonException
     */
    public static function serialize(Event $event): string
    {
        return json_encode($event, JSON_THROW_ON_ERROR);
    }

    /**
     * 序列化批量事件。
     *
     * @param list<Event> $events
     *
     * @throws JsonException
     */
    public static function serializeBatch(array $events): string
    {
        return json_encode($events, JSON_THROW_ON_ERROR);
    }
}
