<?php

declare(strict_types=1);

namespace SensorsWave\Tracking;

use JsonException;
use SensorsWave\Model\Event;

/**
 * Event serializer.
 */
final class EventSerializer
{
    /**
     * Serialize a single event to JSON.
     *
     * @throws JsonException
     */
    public static function serialize(Event $event): string
    {
        return json_encode($event, JSON_THROW_ON_ERROR);
    }

    /**
     * Serialize a batch of events to JSON.
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
