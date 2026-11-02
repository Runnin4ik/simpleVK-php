<?php

declare(strict_types=1);

namespace DigitalStars\SimpleVK\LongPoll;

use DigitalStars\SimpleVK\Event\Update;

/**
 * Дедупликация longpoll-событий.
 *
 * VK переотдаёт события при реконнекте и потере ответа на a_check (failed:2/3),
 * из-за чего бот может ответить дважды. Реализация решает, новое ли событие, и
 * атомарно помечает его обработанным.
 *
 * Контракт нарочно без PSR-16: у SimpleCache нет операции «записать, если нет»
 * (SET NX), а именно она нужна при конкурентной обработке.
 */
interface DedupInterface
{
    /**
     * true — событие новое (и помечено обработанным), false — дубликат.
     */
    public function claim(Update $update): bool;
}
