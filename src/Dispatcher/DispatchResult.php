<?php

declare(strict_types=1);

namespace DigitalStars\SimpleVK\Dispatcher;

use DigitalStars\SimpleVK\Event\UpdateType;

/**
 * Результат программного прогона события через диспетчер.
 *
 * Нужен тестам и песочнице: какой Action сработал, что ушло в VK API
 * (outbox), какие ошибки пойманы и сколько всё заняло. В отличие от
 * `handle()` (который только исполняет), `dispatch()` ничего не теряет.
 */
final readonly class DispatchResult
{
    /**
     * @param array<string, mixed>                                                $routeArgs Аргументы, извлечённые из payload/regex.
     * @param list<array{method: string, params: array<string, mixed>}>           $outbox    Вызовы VK API, сделанные обработчиком (если транспорт их записывает).
     * @param \Throwable|null                                                     $error     Непойманное исключение обработчика.
     */
    public function __construct(
        public ?string $actionClass = null,
        public ?UpdateType $eventType = null,
        public ?int $userId = null,
        public ?int $peerId = null,
        public ?string $text = null,
        /** @var array<string, mixed>|null */
        public ?array $payload = null,
        public array $routeArgs = [],
        public bool $handled = false,
        public ?\Throwable $error = null,
        public float $durationMs = 0.0,
        public array $outbox = [],
    ) {}

    /**
     * Отправленные сообщения (параметры `messages.send`) без служебных вызовов.
     *
     * @return list<array<string, mixed>>
     */
    public function messages(): array
    {
        return \array_values(\array_map(
            static fn(array $call): array => $call['params'],
            \array_values(\array_filter(
                $this->outbox,
                static fn(array $call): bool => $call['method'] === 'messages.send',
            )),
        ));
    }

    /**
     * Точка входа для ассертов в тестах: было ли отправлено сообщение,
     * удовлетворяющее предикату.
     *
     * @param callable(array<string, mixed>): bool|null $predicate
     */
    public function sent(?callable $predicate = null): bool
    {
        foreach ($this->messages() as $params) {
            if ($predicate === null || $predicate($params)) {
                return true;
            }
        }

        return false;
    }
}
