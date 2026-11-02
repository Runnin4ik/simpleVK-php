<?php

declare(strict_types=1);

namespace DigitalStars\SimpleVK\LongPoll;

use DigitalStars\SimpleVK\Event\Update;
use DigitalStars\SimpleVK\Exception\SimpleVkException;

/**
 * Цикл longpoll: получает пачки апдейтов, отсекает дубли и отдаёт события наружу.
 *
 * Раннер не знает, что делать с событием: обработчик решает сам (синхронно,
 * в корутине, в очереди). Так библиотека остаётся без привязки к рантайму —
 * проект может добавить лимит параллельных обработчиков или сигналы поверх.
 *
 * Ошибки poll-а (сеть, failed:1/2/3, истёкший ключ) не завершают цикл: они уходят
 * в onError, после чего раннер ждёт pauseMs и продолжает с переинициализации сервера.
 */
final class LongPollRunner
{
    /**
     * @param \Closure(\Throwable): void|null $onError Вызывается на ошибке poll-а; сама ошибка не летит наружу.
     * @param int                             $pauseMs Пауза после ошибки poll-а, мс.
     */
    public function __construct(
        private readonly LongPollClient $longPoll,
        private readonly ?DedupInterface $dedup = null,
        private readonly ?\Closure $onError = null,
        private readonly int $pauseMs = 200,
        private readonly bool $skipBacklogOnStart = false,
    ) {}

    /**
     * Запускает бесконечный цикл. Возврат — только по исключению обработчика
     * (например, AsyncCancellation при graceful shutdown) или фатальной ошибке.
     *
     * @param callable(Update): void $onUpdate
     */
    public function run(callable $onUpdate): void
    {
        if ($this->skipBacklogOnStart) {
            $this->longPoll->skipBacklog();
        }

        while (true) {
            try {
                $updates = $this->longPoll->wait();
            } catch (SimpleVkException $e) {
                if ($this->onError !== null) {
                    ($this->onError)($e);
                }

                if ($this->pauseMs > 0) {
                    \usleep($this->pauseMs * 1000);
                }

                continue;
            }

            foreach ($updates as $update) {
                if ($this->dedup !== null && !$this->dedup->claim($update)) {
                    continue;
                }

                $onUpdate($update);
            }
        }
    }
}
