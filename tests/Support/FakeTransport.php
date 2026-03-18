<?php

declare(strict_types=1);

namespace SensorsWave\Tests\Support;

use SensorsWave\Http\Request;
use SensorsWave\Http\Response;
use SensorsWave\Http\TransportInterface;

final class FakeTransport implements TransportInterface
{
    /** @var list<Request> */
    public array $requests = [];

    public function send(Request $request): Response
    {
        $this->requests[] = $request;

        return new Response(200, '{}');
    }
}
