<?php

declare(strict_types=1);

namespace DigitalStars\SimpleVK\Testing;

use DigitalStars\SimpleVK\ApiClient;
use DigitalStars\SimpleVK\Event\Update;
use DigitalStars\SimpleVK\Message\IncomingMessage;

/**
 * Фабрика подготовленных апдейтов VK для тестов и песочницы.
 *
 * Избавляет от ручных массивов: форматы message_new/message_event/group_join
 * различаются ключами (from_id против user_id, payload как JSON-строка),
 * и на этом легко получить «событие без пользователя».
 *
 *   $update = TestData::message('/старт', from: 1, peer: 1);
 *   $update = TestData::callback('loot_type', ['type' => 2], from: 1);
 */
final class TestData
{
    /**
     * Апдейт `message_new` (обычное сообщение, команда или текстовая кнопка).
     *
     * @param array<string, mixed> $payload Payload текстовой кнопки.
     * @param array<string, mixed> $extra   Дополнительные поля message (attachments, reply_message…).
     *
     * @return array<string, mixed>
     */
    public static function message(
        string $text = '',
        int $from = 1,
        ?int $peer = null,
        array $payload = [],
        array $extra = [],
        int $eventId = 1,
        int $groupId = 1,
    ): array {
        $message = [
            'id' => $eventId,
            'date' => \time(),
            'from_id' => $from,
            'peer_id' => $peer ?? $from,
            'text' => $text,
            'out' => 0,
        ];

        if ($payload !== []) {
            $message['payload'] = \json_encode($payload, \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
        }

        $message += $extra;

        return [
            'type' => 'message_new',
            'event_id' => \bin2hex(\pack('J', $eventId)),
            'group_id' => $groupId,
            'object' => ['message' => $message],
        ];
    }

    /**
     * Апдейт `message_event` (клик по callback-кнопке).
     *
     * @param array<string, mixed> $payload Payload кнопки (без `action` тоже допустим).
     *
     * @return array<string, mixed>
     */
    public static function callback(
        string $action,
        array $payload = [],
        int $from = 1,
        ?int $peer = null,
        ?int $eventId = null,
        int $groupId = 1,
    ): array {
        $eventId ??= \random_int(1, PHP_INT_MAX);

        return [
            'type' => 'message_event',
            'event_id' => \bin2hex(\pack('J', $eventId)),
            'group_id' => $groupId,
            'object' => [
                'user_id' => $from,
                'peer_id' => $peer ?? $from,
                'event_id' => \bin2hex(\pack('J', $eventId)),
                'payload' => \json_encode(
                    ['action' => $action] + $payload,
                    \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR,
                ),
            ],
        ];
    }

    /**
     * Апдейт `group_join` (подписка на сообщество) — для проверки welcome-сценариев.
     *
     * @return array<string, mixed>
     */
    public static function groupJoin(int $userId, int $groupId = 1, int $eventId = 1): array
    {
        return [
            'type' => 'group_join',
            'event_id' => \bin2hex(\pack('J', $eventId)),
            'group_id' => $groupId,
            'object' => ['user_id' => $userId, 'join_type' => 'join'],
        ];
    }

    /**
     * Произвольный апдейт: сырой тип + object.
     *
     * @param array<string, mixed> $object
     *
     * @return array<string, mixed>
     */
    public static function raw(string $type, array $object = [], int $eventId = 1, int $groupId = 1): array
    {
        return [
            'type' => $type,
            'event_id' => \bin2hex(\pack('J', $eventId)),
            'group_id' => $groupId,
            'object' => $object,
        ];
    }

    /**
     * Типизированное входящее сообщение из апдейта (для тестов, которым нужен IncomingMessage).
     */
    public static function incoming(array $update, ApiClient $api, int $groupId = 1): IncomingMessage
    {
        return new IncomingMessage(Update::fromLongPoll($update), $api, $groupId);
    }
}
