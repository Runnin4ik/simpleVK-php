<?php

declare(strict_types=1);

namespace DigitalStars\SimpleVK\LongPoll;

use DigitalStars\SimpleVK\Event\Update;
use DigitalStars\SimpleVK\Exception\SimpleVkException;

/**
 * Цикл longpoll: получает пачки апдейтов, отсекает дубли и отдаёт события наружу.
 *
 * Раннер отдаёт пачку событий целиком: обработчик волен разобрать её синхронно,
 * параллельно в корутинах или в очереди. Пачка — естественный предел параллелизма,
 * поэтому следующий poll начинается только после возврата обработчика (backpressure).
 * Библиотека остаётся без привязки к рантайму: корутины и сигналы — дело проекта.
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
     * @param callable(list<Update>): void $onBatch Пачка уже без дублей; может быть пустой.
     */
    public function run(callable $onBatch): void
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

            $fresh = [];

            foreach ($updates as $update) {
                if ($this->dedup === null || $this->dedup->claim($update)) {
                    $fresh[] = $update;
                }
            }

            $onBatch($fresh);
        }
    }
}
