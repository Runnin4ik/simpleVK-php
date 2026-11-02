<?php

declare(strict_types=1);

namespace DigitalStars\SimpleVK\Service;

use DigitalStars\SimpleVK\ApiClient;

/**
 * Подстановки вида `~!full~`, `~!fn|123~`, `~full|-456~` (аналог SimpleVK v3).
 *
 * Поддерживаемые теги: !fn, !ln, !full, fn, ln, full. Пользовательские теги
 * раскрываются в VK-упоминание `@id123(Имя)`, групповые — в `@club456(Имя)`.
 * Без обращений к API текст возвращается как есть.
 */
final class Placeholders
{
    private const array TAGS = ['!fn', '!ln', '!full', 'fn', 'ln', 'full'];

    /**
     * Раскрывает подстановки в тексте.
     *
     * @param int|null $currentUserId Кому адресовано `~тег~` без `|id`.
     */
    public static function resolve(ApiClient $api, string $text, ?int $currentUserId = null): string
    {
        if (!\preg_match_all('|~(.*?)~|', $text, $matches)) {
            return $text;
        }

        $userIds = [];
        $groupIds = [];

        foreach ($matches[1] as $match) {
            [$tag, $rawId] = \array_pad(\explode('|', $match, 2), 2, null);
            if (!\in_array($tag, self::TAGS, true)) {
                continue;
            }

            $vkId = $rawId !== null ? (int) $rawId : (int) $currentUserId;
            if ($vkId > 0) {
                $userIds[$vkId] = $vkId;
            } elseif ($vkId < 0) {
                $groupIds[\abs($vkId)] = \abs($vkId);
            }
        }

        $users = $userIds === [] ? [] : $api->call('users.get', ['user_ids' => \implode(',', $userIds)]);

        $groups = $groupIds === []
            ? []
            : $api->call('groups.getById', ['group_ids' => \implode(',', $groupIds)])['groups'] ?? [];

        $usersById = [];
        foreach (\is_array($users) ? $users : [] as $user) {
            $usersById[(int) $user['id']] = $user;
        }

        $groupsById = [];
        foreach (\is_array($groups) ? $groups : [] as $group) {
            $groupsById[(int) $group['id']] = $group;
        }

        return (string) \preg_replace_callback(
            '|~(.*?)~|',
            static function (array $match) use ($usersById, $groupsById, $currentUserId): string {
                [$tag, $rawId] = \array_pad(\explode('|', $match[1], 2), 2, null);
                if (!\in_array($tag, self::TAGS, true)) {
                    return $match[0];
                }

                $vkId = $rawId !== null ? (int) $rawId : (int) $currentUserId;

                if ($vkId > 0 && isset($usersById[$vkId])) {
                    $first = (string) ($usersById[$vkId]['first_name'] ?? '');
                    $last = (string) ($usersById[$vkId]['last_name'] ?? '');
                    $replace = [
                        '!fn' => "@id{$vkId}({$first})",
                        '!ln' => "@id{$vkId}({$last})",
                        '!full' => "@id{$vkId}({$first} {$last})",
                        'fn' => $first,
                        'ln' => $last,
                        'full' => \trim("{$first} {$last}"),
                    ];

                    return $replace[$tag] ?? $match[0];
                }

                if ($vkId < 0 && isset($groupsById[\abs($vkId)])) {
                    $groupId = \abs($vkId);

                    return "@club{$groupId}({$groupsById[$groupId]['name']})";
                }

                return $match[0];
            },
            $text,
        );
    }
}
