<?php

declare(strict_types=1);

namespace DigitalStars\SimpleVK\Tests;

use DigitalStars\SimpleVK\Service\ErrorFormatter;
use DigitalStars\SimpleVK\Service\ErrorReporter;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

final class ErrorFormatterTest extends TestCase
{
    public function testRedactsSecretsAndTruncatesVkMessage(): void
    {
        $formatter = new ErrorFormatter(appTitle: 'Tower', maxVkLength: 120);
        $report = $formatter->format(new \RuntimeException(
            'access_token=secret-token sign=abcdef ' . \str_repeat('x', 300),
        ));

        self::assertStringNotContainsString('secret-token', $report->vk);
        self::assertStringNotContainsString('abcdef', $report->vk);
        self::assertStringContainsString('access_token=***', $report->vk);
        self::assertLessThanOrEqual(120, \mb_strlen($report->vk));
    }

    public function testVkMatchesV3Format(): void
    {
        $report = (new ErrorFormatter(appTitle: 'Tower'))
            ->format(new \RuntimeException('boom'));

        self::assertStringStartsWith('‼Fatal Error: boom', $report->vk);
        self::assertMatchesRegularExpression('/➡ #1 .*ErrorFormatterTest\.php:\d+/', $report->vk);
        self::assertStringContainsString(':', $report->vk);
    }

    public function testConsoleHasAnsiAndPlainDoesNot(): void
    {
        $report = (new ErrorFormatter())->format(new \RuntimeException('boom'));

        self::assertStringContainsString("\033[", $report->console);
        self::assertStringNotContainsString("\033[", $report->plain);
        self::assertStringStartsWith('‼Fatal Error: boom', $report->plain);
    }

    public function testShortTraceKeepsProjectFrameAndSnippet(): void
    {
        $report = (new ErrorFormatter())->shortTrace()
            ->format(new \RuntimeException('with snippet'));

        self::assertStringContainsString(\basename(__FILE__), $report->plain);
        self::assertMatchesRegularExpression('/\d+: .*with snippet|with snippet/', $report->plain);
    }

    public function testTracePathFilterShortensVkPaths(): void
    {
        $report = (new ErrorFormatter())->withTracePathFilter(\dirname(__FILE__))
            ->format(new \RuntimeException('filtered'));

        self::assertStringContainsString('..', $report->vk);
        self::assertStringContainsString('ErrorFormatterTest.php', $report->vk);
    }

    public function testFingerprintIgnoresNumbers(): void
    {
        $formatter = new ErrorFormatter();
        $a = $formatter->format($this->failure('request 100 failed'));
        $b = $formatter->format($this->failure('request 250 failed'));

        self::assertSame($a->fingerprint, $b->fingerprint);
    }

    public function testReporterLogsExceptionObjectAndSuppressesDuplicateNotification(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<array{message: string, context: array<string, mixed>}> */
            public array $records = [];

            public function log(mixed $level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['message' => (string) $message, 'context' => $context];
            }
        };

        $sent = [];
        $reporter = new ErrorReporter(
            logger: $logger,
            notifyVkId: 42,
            messageSender: static function (string $text, int $vkId) use (&$sent): void {
                $sent[] = [$text, $vkId];
            },
            notifyIntervalSeconds: 60,
        );

        $error = new \RuntimeException('duplicate');
        $reporter->report($error);
        $reporter->report($error);

        self::assertCount(2, $logger->records, 'оба события должны попасть в лог');
        self::assertSame($error, $logger->records[0]['context']['exception']);
        self::assertCount(1, $sent, 'повторное уведомление в VK подавляется');
    }

    private function failure(string $message): \RuntimeException
    {
        return new \RuntimeException($message);
    }

    public function testNotificationFailureDoesNotThrow(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $messages = [];

            public function log(mixed $level, string|\Stringable $message, array $context = []): void
            {
                $this->messages[] = (string) $message;
            }
        };

        $reporter = new ErrorReporter(
            logger: $logger,
            notifyVkId: 42,
            messageSender: static function (): void {
                throw new \RuntimeException('VK is down');
            },
        );

        $reporter->report(new \RuntimeException('boom'));

        self::assertStringContainsString('VK error notification failed', $logger->messages[1] ?? '');
    }
}
