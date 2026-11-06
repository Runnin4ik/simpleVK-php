<?php

declare(strict_types=1);

namespace DigitalStars\SimpleVK\Service;

/**
 * Готовые представления одной ошибки: для консоли (с ANSI), для лога
 * (без ANSI) и для короткого уведомления в VK.
 */
final readonly class ErrorReport
{
    public function __construct(
        public string $plain,
        public string $console,
        public string $vk,
        public string $fingerprint,
    ) {}
}
