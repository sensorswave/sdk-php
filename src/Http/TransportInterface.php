<?php

declare(strict_types=1);

namespace SensorsWave\Http;

/**
 * HTTP transport interface.
 */
interface TransportInterface
{
    /**
     * Send an HTTP request and return the response.
     */
    public function send(Request $request): Response;
}
