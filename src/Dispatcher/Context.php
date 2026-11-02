<?php

declare(strict_types=1);

namespace DigitalStars\SimpleVK\Dispatcher;

use DigitalStars\SimpleVK\ApiClient;
use DigitalStars\SimpleVK\Message\IncomingMessage;
use DigitalStars\SimpleVK\Message\OutgoingMessage;
use ReflectionClass;
use RuntimeException;

/**
 * Контекст обработки события: данные события, DI, запуск Actions/Views.
 */
class Context
{
    /** @var class-string|null Класс текущего обрабатываемого Action. */
    public ?string $actionClass = null;

    /** @var array<string, mixed> */
    private array $attributes = [];

    public function __construct(
        public readonly ApiClient $api,
        private readonly EventDispatcher $dispatcher,
        private readonly ArgumentResolver $argumentResolver,
        /**
         * Объект-обёртка исходного события: IncomingMessage или сырой массив callback/прочих событий.
         */
        public readonly object|array $event,
        public readonly ?int $userId = null,
        public readonly ?int $peerId = null,
        public readonly ?string $messageText = null,
        /**
         * Фабрика DI: class-string => объект. Closure, т.к. свойства не могут быть callable.
         */
        private readonly ?\Closure $factory = null,
    ) {}

    public function getAttribute(string $name, mixed $default = null): mixed
    {
        return $this->attributes[$name] ?? $default;
    }

    public function setAttribute(string $name, mixed $value): self
    {
        $this->attributes[$name] = $value;

        return $this;
    }

    /**
     * Получает экземпляр класса из DI-контейнера/фабрики.
     *
     * @param class-string $className
     */
    public function get(string $className): object
    {
        if (\is_a($this, $className)) {
            return $this;
        }

        if (!\is_callable($this->factory)) {
            throw new RuntimeException('DI-фабрика не задана (DispatcherConfig::withContainer/withFactory)');
        }

        /** @var object $instance */
        return ($this->factory)($className);
    }

    /**
     * Создаёт и рендерит View (render() резолвится через DI).
     */
    public function getView(string $viewClass, mixed ...$args): OutgoingMessage
    {
        $instance = $this->dispatcher->createInstance($viewClass, $this);

        if (!\method_exists($instance, 'render') || !\class_exists($viewClass)) {
            throw new RuntimeException("Класс {$viewClass} должен иметь метод render()");
        }

        $reflectionMethod = new ReflectionClass($viewClass)->getMethod('render');
        $resolvedArgs = $this->argumentResolver->getArguments($reflectionMethod, $this, $args);

        return $instance->render(...$resolvedArgs);
    }

    /**
     * Рендерит и отправляет View текущему пользователю.
     *
     * @return list<int> ID отправленных сообщений.
     */
    public function sendView(string $viewClass, mixed ...$args): array
    {
        return $this->getView($viewClass, ...$args)->send();
    }

    /**
     * Запускает другой Action в рамках текущего запроса.
     *
     * @param class-string $actionClass
     * @param array<string, mixed> $args
     */
    public function run(string $actionClass, array $args = []): void
    {
        $this->dispatcher->runAction($actionClass, $this, $args);
    }

    /**
     * Сырой массив входящего сообщения, если событие — message_new; иначе null.
     *
     * @return array<string, mixed>|null
     */
    public function incomingMessageRaw(): ?array
    {
        if ($this->event instanceof IncomingMessage) {
            return $this->event->raw();
        }

        if (\is_array($this->event) && \is_array($this->event['message'] ?? null)) {
            return $this->event['message'];
        }

        return null;
    }

    /**
     * Входящее сообщение как типизированный объект (только для message-событий).
     */
    public function incoming(): ?IncomingMessage
    {
        return $this->event instanceof IncomingMessage ? $this->event : null;
    }

    /**
     * Ответ на входящее сообщение — отправляет сразу, как v3 `$ctx->reply()`.
     *
     * Возвращает conversation_message_id первого отправленного сообщения
     * (или null), поэтому существующий код `$ctx->reply('текст');` работает
     * без `->send()`. Для цепочки с клавиатурой есть replyMessage().
     */
    public function reply(string $text = ''): ?int
    {
        $ids = $this->replyMessage($text)->send();

        return $ids[0] ?? null;
    }

    /**
     * Конструктор ответа на входящее сообщение без немедленной отправки.
     *
     * Для сообщений ставит reply_to на исходное; для callback и прочих
     * событий вырождается в обычное сообщение в тот же peer.
     */
    public function replyMessage(string $text = ''): OutgoingMessage
    {
        $incoming = $this->incoming();

        if ($incoming !== null) {
            return $incoming->reply($text);
        }

        return $this->msg($text);
    }

    /**
     * Быстрый доступ к конструктору исходящего сообщения текущего диалога.
     */
    public function msg(string $text = ''): OutgoingMessage
    {
        $message = new OutgoingMessage($this->api);

        if ($this->peerId !== null) {
            $message->to($this->peerId);
        } elseif ($this->userId !== null) {
            $message->toUser($this->userId);
        }

        return $message->text($text);
    }
}
