<?php

declare(strict_types=1);

namespace SensorsWave\Http;

/**
 * Transport that can send independent HTTP requests concurrently.
 */
interface ConcurrentTransportInterface extends TransportInterface
{
    /**
     * @param list<Request> $requests
     *
     * @return list<Response|\Throwable>
     */
    public function sendConcurrent(array $requests, int $concurrency): array;
}
