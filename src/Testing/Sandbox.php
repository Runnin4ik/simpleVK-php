<?php

declare(strict_types=1);

namespace DigitalStars\SimpleVK\Testing;

use DigitalStars\SimpleVK\ApiClient;
use DigitalStars\SimpleVK\Config\ClientConfig;
use DigitalStars\SimpleVK\Dispatcher\ArgumentResolver;
use DigitalStars\SimpleVK\Dispatcher\DispatcherConfig;
use DigitalStars\SimpleVK\Dispatcher\DispatchResult;
use DigitalStars\SimpleVK\Dispatcher\EventDispatcher;
use DigitalStars\SimpleVK\Transport\FakeTransport;
use Psr\Container\ContainerInterface;

/**
 * Оффлайн-песочница: прогоняет подготовленные апдейты через реальный диспетчер,
 * но вместо VK API пишет исходящие вызовы в FakeTransport.
 *
 * Используется в тестах и в CLI (`bin/svk-sandbox`), чтобы «отправить» боту
 * команду и увидеть, что он ответил, — без сети и без живого сообщества.
 */
final class Sandbox
{
    private function __construct(
        public readonly EventDispatcher $dispatcher,
        public readonly FakeTransport $transport,
        public readonly ApiClient $api,
    ) {}

    /**
     * @param list<string>                                      $actionsPaths Каталоги с Action-классами.
     * @param array<string, mixed>                              $responses    Заготовки ответов VK API (method => response).
     * @param ContainerInterface|null                           $container    DI-контейнер приложения (опционально).
     */
    public static function create(
        array $actionsPaths,
        array $responses = [],
        ?ContainerInterface $container = null,
        bool $debug = false,
    ): self {
        $transport = new FakeTransport(
            $responses !== []
                ? $responses
                : [
                    'messages.send' => [['message_id' => 1]],
                    'messages.edit' => 1,
                    'groups.getById' => ['groups' => [['id' => 1]]],
                ],
        );

        // токен не нужен (запросы не уходят), но ClientConfig требует непустой
        $token = (string) (\getenv('SVK_SANDBOX_TOKEN') ?: 'offline');
        $api = new ApiClient(ClientConfig::create(token: $token, groupId: 1), $transport);

        $config = new DispatcherConfig($actionsPaths, $debug);
        if ($container !== null) {
            $config = $config->withContainer($container);
        }

        return new self(new EventDispatcher($config, new ArgumentResolver(), $api), $transport, $api);
    }

    /**
     * Прогоняет апдейт и возвращает результат (ошибки не летят наружу).
     *
     * @param array<string, mixed> $update
     */
    public function dispatch(array $update): DispatchResult
    {
        return $this->dispatcher->dispatch($update, throwOnError: false);
    }

    /**
     * Прогоняет команду как обычное сообщение.
     */
    public function say(string $text, int $from = 1, ?int $peer = null): DispatchResult
    {
        return $this->dispatch(TestData::message($text, from: $from, peer: $peer));
    }

    /**
     * Прогоняет клик по callback-кнопке.
     *
     * @param array<string, mixed> $payload
     */
    public function click(string $action, array $payload = [], int $from = 1): DispatchResult
    {
        return $this->dispatch(TestData::callback($action, $payload, from: $from));
    }

    /**
     * Разбирает апдейт из JSON (строка или файл) и прогоняет его.
     */
    public function dispatchJson(string $json): DispatchResult
    {
        /** @var array<string, mixed> $update */
        $update = \json_decode($json, true, flags: \JSON_THROW_ON_ERROR);

        return $this->dispatch($update);
    }
}
