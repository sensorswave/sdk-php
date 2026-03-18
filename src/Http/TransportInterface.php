<?php

declare(strict_types=1);

namespace SensorsWave\Http;

/**
 * HTTP 传输接口。
 */
interface TransportInterface
{
    /**
     * 发送 HTTP 请求。
     */
    public function send(Request $request): Response;
}
