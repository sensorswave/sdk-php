<?php

declare(strict_types=1);

namespace SensorsWave\Contract;

use SensorsWave\Storage\QueueMessage;

/**
 * 事件队列抽象。
 *
 * 注意：当前 ack/nack 的粒度是 receipt 级别（即同一次 dequeue 返回的所有消息共享
 * 同一个 receipt）。调用方必须整批 ack 或整批 nack，不支持对同一 receipt 下的消息
 * 做部分确认——部分 ack 会导致同批剩余消息一起被确认，部分 nack 会导致整批被重投。
 */
interface EventQueueInterface
{
    /** @param list<string> $payloads */
    public function enqueue(array $payloads): void;

    /** @return list<QueueMessage> */
    public function dequeue(int $limit): array;

    /**
     * 确认消息已成功处理。同一 receipt 下的消息必须整批确认。
     *
     * @param list<QueueMessage> $messages
     */
    public function ack(array $messages): void;

    /**
     * 将消息退回队列以便重新投递。同一 receipt 下的消息必须整批退回。
     *
     * @param list<QueueMessage> $messages
     */
    public function nack(array $messages): void;
}
