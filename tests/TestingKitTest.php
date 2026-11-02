<?php

declare(strict_types=1);

namespace DigitalStars\SimpleVK\Tests;

use DigitalStars\SimpleVK\Dispatcher\DispatchResult;
use DigitalStars\SimpleVK\Event\UpdateType;
use DigitalStars\SimpleVK\Testing\Sandbox;
use DigitalStars\SimpleVK\Testing\TestData;
use DigitalStars\SimpleVK\Tests\tmp_kit\KitButton;
use DigitalStars\SimpleVK\Tests\tmp_kit\KitReplyAction;
use DigitalStars\SimpleVK\Tests\tmp_kit\KitStartAction;
use DigitalStars\SimpleVK\Tests\tmp_kit\KitThrowingAction;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Набор «тестируй бота оффлайн»: TestData + Sandbox + DispatchResult.
 *
 * Это то, чего не хватало в v3: прогнать подготовленный апдейт через реальный
 * диспетчер и увидеть, что именно бот «отправил», без сети и без моков Message.
 */
final class TestingKitTest extends TestCase
{
    private Sandbox $sandbox;

    protected function setUp(): void
    {
        KitStartAction::$hits = 0;

        $this->sandbox = Sandbox::create([__DIR__ . '/tmp_kit']);
    }

    public function testDispatchReturnsActionAndCapturesOutbox(): void
    {
        $result = $this->sandbox->say('/start', from: 10, peer: 10);

        self::assertInstanceOf(DispatchResult::class, $result);
        self::assertSame(KitStartAction::class, $result->actionClass);
        self::assertTrue($result->handled);
        self::assertSame(1, KitStartAction::$hits);
        self::assertSame(UpdateType::MessageNew, $result->eventType);
        self::assertSame(10, $result->userId);
        self::assertSame(10, $result->peerId);
        self::assertNull($result->error);

        self::assertTrue($result->sent(static fn(array $p): bool => $p['message'] === 'start-ok'));
        self::assertSame('start-ok', $result->messages()[0]['message']);
        self::assertSame(1, $result->outbox[0]['method'] === 'messages.send' ? 1 : 0);
        self::assertGreaterThanOrEqual(0.0, $result->durationMs);
    }

    public function testCallbackClickRoutesByPayloadAndUsesUserId(): void
    {
        $result = $this->sandbox->dispatch(TestData::callback('KitButton', ['extra' => 'x'], from: 20, peer: 20));

        self::assertSame(KitButton::class, $result->actionClass);
        self::assertSame(20, $result->userId, 'у message_event автор лежит в user_id, а не from_id');
        self::assertSame(['extra' => 'x'], KitButton::$lastArgs);
        self::assertTrue($result->sent(static fn(array $p): bool => $p['message'] === 'pong'));
    }

    public function testHandlerExceptionIsCapturedNotThrown(): void
    {
        $result = $this->sandbox->say('/boom');

        self::assertSame(KitThrowingAction::class, $result->actionClass);
        self::assertFalse($result->handled);
        self::assertInstanceOf(RuntimeException::class, $result->error);
        self::assertSame('взрыв', $result->error->getMessage());
        self::assertSame([], $result->outbox);
    }

    public function testHandleStillThrowsForBackwardsCompatibility(): void
    {
        $this->expectException(RuntimeException::class);

        $this->sandbox->dispatcher->handle(TestData::message('/boom'));
    }

    public function testUnknownTextWithoutFallbackIsUnhandled(): void
    {
        $result = $this->sandbox->say('/nope');

        self::assertNull($result->actionClass);
        self::assertFalse($result->handled);
        self::assertSame([], $result->outbox);
    }

    public function testReplyAndKbdShimsProduceReplyToAndKeyboard(): void
    {
        $result = $this->sandbox->say('/reply', from: 30, peer: 30);

        self::assertSame(KitReplyAction::class, $result->actionClass);

        $params = $result->messages()[0];
        self::assertSame('ответ', $params['message']);
        self::assertSame(1, $params['reply_to'], 'Context::reply() ставит reply_to на исходное сообщение');
        self::assertArrayHasKey('keyboard', $params);
        self::assertStringContainsString('KitButton', (string) $params['keyboard']);
        self::assertStringContainsString(
            'primary',
            (string) $params['keyboard'],
            'цвет green маппится в primary (VK-палитра)',
        );
    }

    public function testTestDataShapes(): void
    {
        $message = TestData::message('/start', from: 5, peer: 6, payload: ['k' => 'v']);

        self::assertSame('message_new', $message['type']);
        self::assertSame(5, $message['object']['message']['from_id']);
        self::assertSame(6, $message['object']['message']['peer_id']);
        self::assertSame('{"k":"v"}', $message['object']['message']['payload']);

        $callback = TestData::callback('KitButton', ['a' => 1], from: 7);
        self::assertSame('message_event', $callback['type']);
        self::assertSame(7, $callback['object']['user_id']);
        self::assertSame('{"action":"KitButton","a":1}', $callback['object']['payload']);

        $join = TestData::groupJoin(9);
        self::assertSame('group_join', $join['type']);
        self::assertSame(9, $join['object']['user_id']);

        self::assertSame('wall_post_new', TestData::raw('wall_post_new')['type']);
    }

    public function testSandboxCanDispatchJson(): void
    {
        $json = \json_encode(TestData::message('/start', from: 40, peer: 40), JSON_THROW_ON_ERROR);

        $result = $this->sandbox->dispatchJson($json);

        self::assertSame(KitStartAction::class, $result->actionClass);
        self::assertTrue($result->sent());
    }
}
