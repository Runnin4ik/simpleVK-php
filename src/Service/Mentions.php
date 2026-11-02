<?php

declare(strict_types=1);

namespace DigitalStars\SimpleVK\Service;

use DigitalStars\SimpleVK\ApiClient;

/**
 * Форматирование упоминаний в тексте сообщений.
 *
 * VK-разметка: [id123|Имя] — упоминание пользователя,
 * [club456|Группа] — сообщества.
 */
final class Mentions
{
    /**
     * Упоминание пользователя по ID и имени.
     */
    public static function user(int $userId, string $displayName): string
    {
        return "[id{$userId}|{$displayName}]";
    }

    /**
     * Упоминание сообщества.
     */
    public static function group(int $groupId, string $displayName): string
    {
        // Отрицательный ID → club
        if ($groupId < 0) {
            return '[club' . \abs($groupId) . "|{$displayName}]";
        }

        return "[club{$groupId}|{$displayName}]";
    }

    /**
     * Пользователи и сообщества, «затронутые» сообщением: пересылки, ответ,
     * упоминания в тексте и ссылки vk.ru/vk.com (аналог v3 getAffectedUsers).
     *
     * @param array<string, mixed> $message Сырое сообщение VK (объект message либо его контейнер).
     * @param list<string>|string  $category Категории: fwd, reply, mention, url.
     *
     * @return ($useCategory is true ? array<string, list<int>|null> : list<int>) ID; группы отрицательные.
     */
    public static function affectedUsers(
        ApiClient $api,
        array $message,
        bool $useCategory = false,
        array|string $category = ['fwd', 'reply', 'mention', 'url'],
    ): array {
        $categories = \is_array($category) ? $category : [$category];
        $body = \is_array($message['message'] ?? null) ? $message['message'] : $message;
        $affected = [];

        if (\in_array('fwd', $categories, true) && \is_array($body['fwd_messages'] ?? null)) {
            foreach ($body['fwd_messages'] as $forwarded) {
                $affected['fwd'] = [
                    (int) ($forwarded['from_id'] ?? 0),
                    ...self::mentionedIds((string) ($forwarded['text'] ?? '')),
                ];
            }
        }

        if (\in_array('reply', $categories, true) && \is_array($body['reply_message'] ?? null)) {
            $reply = $body['reply_message'];
            $affected['reply'] = [
                (int) ($reply['from_id'] ?? 0),
                ...self::mentionedIds((string) ($reply['text'] ?? '')),
            ];
        }

        $text = (string) ($body['text'] ?? '');

        if (\in_array('mention', $categories, true)) {
            $affected['mention'] = self::mentionedIds($text);
        }

        if (\in_array('url', $categories, true)) {
            $affected['url'] = self::resolveLinks($api, $text);
        }

        if (!$useCategory) {
            $flat = [];
            foreach ($affected as $ids) {
                $flat = [...$flat, ...($ids ?? [])];
            }

            return \array_values(\array_unique(\array_filter($flat)));
        }

        return $affected;
    }

    /**
     * @return list<int>
     */
    private static function mentionedIds(string $text): array
    {
        $matches = [];
        \preg_match_all('/\[(id|club|public)(\d+)\|/', $text, $matches, \PREG_SET_ORDER);

        return \array_map(static fn(array $m): int => $m[1] === 'id' ? (int) $m[2] : -(int) $m[2], $matches);
    }

    /**
     * Ссылки на профили/сообщества: числовой id берётся как есть, screen_name
     * разрешается одним запросом utils.resolveScreenName.
     *
     * @return list<int>|null
     */
    private static function resolveLinks(ApiClient $api, string $text): ?array
    {
        $matches = [];
        \preg_match_all('#vk\.(?:ru|com)/(?:id(\d+)|([a-z0-9_.]+))#i', $text, $matches);
        $raw = \array_values(\array_filter(\array_merge($matches[1], $matches[2])));

        if ($raw === []) {
            return null;
        }

        $resolved = [];
        foreach ($raw as $candidate) {
            if (\ctype_digit($candidate)) {
                $resolved[] = (int) $candidate;
                continue;
            }

            $screen = $api->call('utils.resolveScreenName', ['screen_name' => $candidate]);
            $type = $screen['type'] ?? null;
            $objectId = (int) ($screen['object_id'] ?? 0);

            if ($type === 'user') {
                $resolved[] = $objectId;
            } elseif ($type !== null && $objectId > 0) {
                $resolved[] = -$objectId;
            }
        }

        return $resolved === [] ? null : $resolved;
    }

    /**
     * Заменяет @screen_name на VK-упоминания (аналог placeholders v3).
     *
     * Пример: '@petr, привет' → '[id123|Пётр], привет'
     *
     * @param array<string, string> $mentionsMap '@screen_name' => 'Имя или id123'
     */
    public static function replaceScreenNames(string $text, array $mentionsMap): string
    {
        foreach ($mentionsMap as $screenName => $replacement) {
            $needle = '@' . \ltrim($screenName, '@');
            $text = \str_ireplace($needle, $replacement, $text);
        }

        return $text;
    }
}
