<?php

declare(strict_types=1);

namespace DigitalStars\SimpleVK\Service;

use Psr\Log\LoggerInterface;

/**
 * Компактный отчёт об ошибках: разворачивает Throwable в читаемый текст,
 * пишет его в PSR-3 логгер и при необходимости отправляет короткое
 * уведомление разработчику в VK.
 *
 * Порт идей v3 (setUserLogError/setTracePathFilter/shortTrace), но без
 * глобального состояния: экземпляр создаётся явно и подключается как
 * middleware. Никаких set_error_handler, ini_set, exit и отправки
 * сообщений из глобального обработчика.
 */
final class ErrorReporter
{
    private ErrorFormatter $formatter;

    private ?float $lastNotifyAt = null;

    private string $lastFingerprint = '';

    public function __construct(
        private readonly LoggerInterface $logger,
        /** ID пользователя VK для уведомлений; null — не отправлять. */
        private readonly ?int $notifyVkId = null,
        private readonly ?\Closure $messageSender = null,
        string $appTitle = 'App',
        ?ErrorFormatter $formatter = null,
        /** ANSI-цвета в консольном представлении (для stderr/journald лучше false). */
        private readonly bool $consoleColors = false,
        /** Не отправлять одинаковую ошибку чаще, чем раз в N секунд. 0 — без лимита. */
        private readonly int $notifyIntervalSeconds = 60,
    ) {
        $this->formatter = $formatter ?? new ErrorFormatter(appTitle: $appTitle);
    }

    /**
     * Показывать в trace только кадры, содержащие данные подстроки.
     */
    public function withTracePathFilter(string ...$substrings): self
    {
        $clone = clone $this;
        $clone->formatter = $this->formatter->withTracePathFilter(...$substrings);

        return $clone;
    }

    /**
     * Короткий режим: только значимые кадры без vendor/библиотеки.
     */
    public function shortTrace(): self
    {
        $clone = clone $this;
        $clone->formatter = $this->formatter->shortTrace();

        return $clone;
    }

    /**
     * Обработка исключения: лог + уведомление. Ничего не бросает.
     */
    public function report(\Throwable $e, string $contextDescription = ''): void
    {
        $report = $this->formatter->format($e, $contextDescription);

        $this->logger->error($this->consoleColors ? $report->console : $report->plain, [
            'exception' => $e,
            'code' => $e->getCode(),
            'file' => $e->getFile() . ':' . $e->getLine(),
            'fingerprint' => $report->fingerprint,
        ]);

        if ($this->notifyVkId === null || !$this->messageSender instanceof \Closure) {
            return;
        }

        if ($this->isDuplicate($report->fingerprint)) {
            return;
        }

        try {
            ($this->messageSender)($report->vk, $this->notifyVkId);
        } catch (\Throwable $notifyError) {
            // Ошибка отправки не должна рекурсивно попадать в ErrorReporter.
            $this->logger->warning('VK error notification failed', [
                'exception' => $notifyError,
            ]);
        }
    }

    /**
     * Middleware-обёртка: fn(callable $handler): callable.
     *
     * Использование:
     *   $reporter = new ErrorReporter($logger);
     *   $wrapped = $reporter->middleware();
     *   $wrapped(fn() => $bot->dispatch($update));
     */
    public function middleware(): callable
    {
        return function (callable $handler): callable {
            $self = $this;

            return static function (mixed ...$args) use ($self, $handler): void {
                try {
                    $handler(...$args);
                } catch (\Throwable $e) {
                    $self->report($e);
                }
            };
        };
    }

    private function isDuplicate(string $fingerprint): bool
    {
        if ($this->notifyIntervalSeconds <= 0) {
            return false;
        }

        $now = \microtime(true);
        if (
            $fingerprint === $this->lastFingerprint
            && $this->lastNotifyAt !== null
            && ($now - $this->lastNotifyAt) < $this->notifyIntervalSeconds
        ) {
            return true;
        }

        $this->lastFingerprint = $fingerprint;
        $this->lastNotifyAt = $now;

        return false;
    }
}
