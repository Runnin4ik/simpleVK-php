<?php

declare(strict_types=1);

namespace DigitalStars\SimpleVK\Tests;

use DigitalStars\SimpleVK\ApiClient;
use DigitalStars\SimpleVK\Config\ClientConfig;
use DigitalStars\SimpleVK\Event\Update;
use DigitalStars\SimpleVK\Exception\SimpleVkException;
use DigitalStars\SimpleVK\LongPoll\DedupInterface;
use DigitalStars\SimpleVK\LongPoll\LongPollClient;
use DigitalStars\SimpleVK\LongPoll\LongPollRunner;
use PHPUnit\Framework\TestCase;

/**
 * Раннер отделён от транспорта: подменяем wait() и проверяем дедуп, ошибки
 * и порядок инициализации, не поднимая HTTP.
 */
final class LongPollRunnerTest extends TestCase
{
    public function testDedupSkipsDuplicatesAndDeliversTheRest(): void
    {
        $longPoll = new StubLongPoll([[self::update(1), self::update(2), self::update(3)]]);
        $dedup = new class implements DedupInterface {
            private int $calls = 0;

            public function claim(Update $update): bool
            {
                return ++$this->calls !== 2;
            }
        };

        $delivered = [];
        $runner = new LongPollRunner($longPoll, $dedup);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('stop');

        $runner->run(static function (Update $update) use (&$delivered): void {
            $delivered[] = $update->eventId;

            if (\count($delivered) === 2) {
                throw new \RuntimeException('stop');
            }
        });
    }

    public function testDedupIsAskedForEveryUpdateInOrder(): void
    {
        $longPoll = new StubLongPoll([[self::update(7), self::update(8)]]);
        $dedup = new class implements DedupInterface {
            /** @var list<int> */
            public array $claimed = [];

            public function claim(Update $update): bool
            {
                $this->claimed[] = $update->eventId;

                return false; // всё считается дублями
            }
        };

        $runner = new LongPollRunner($longPoll, $dedup);
        $seen = [];

        $longPoll->onWait = static function (int $call) use ($runner, &$seen): void {
            if ($call > 1) {
                throw new \RuntimeException('stop');
            }
        };

        try {
            $runner->run(static function (Update $update) use (&$seen): void {
                $seen[] = $update->eventId;
            });
        } catch (\RuntimeException) {
            // цикл остановлен из wait()
        }

        self::assertSame([7, 8], $dedup->claimed);
        self::assertSame([], $seen, 'дубли не доходят до обработчика');
    }

    public function testPollErrorGoesToOnErrorThenLoopContinues(): void
    {
        $longPoll = new StubLongPoll([[self::update(1)]]);
        $longPoll->failOnWaitCall = 1;

        $errors = [];
        $runner = new LongPollRunner(
            $longPoll,
            onError: static function (\Throwable $e) use (&$errors): void {
                $errors[] = $e->getMessage();
            },
            pauseMs: 0,
        );

        $received = [];
        $longPoll->onWait = static function (int $call): void {
            if ($call > 2) {
                throw new \RuntimeException('stop');
            }
        };

        try {
            $runner->run(static function (Update $update) use (&$received): void {
                $received[] = $update->eventId;
            });
        } catch (\RuntimeException) {
            // после второй попытки wait() цикл прерван тестом
        }

        self::assertSame(['VK longpoll failed: 2'], $errors);
        self::assertSame([1], $received, 'после ошибки цикл продолжился и получил апдейт');
    }

    public function testSkipBacklogCalledOnceBeforeLoop(): void
    {
        $longPoll = new StubLongPoll([]);
        $longPoll->onWait = static fn(int $call) => throw new \RuntimeException('stop');

        $runner = new LongPollRunner($longPoll, skipBacklogOnStart: true);

        try {
            $runner->run(static fn(Update $u): null => null);
        } catch (\RuntimeException) {
            // ожидаемо
        }

        self::assertSame(1, $longPoll->skipBacklogCalls);
    }

    private static function update(int $eventId): Update
    {
        return Update::fromLongPoll([
            'type' => 'message_new',
            'event_id' => (string) $eventId,
            'group_id' => 1,
            'object' => ['message' => ['from_id' => 1, 'text' => 'x']],
        ]);
    }
}
