<?php

declare(strict_types=1);

namespace DigitalStars\SimpleVK\Service;

/**
 * Превращает Throwable в три представления без глобального состояния:
 * console (ANSI), plain (для логов) и короткий текст для VK.
 *
 * В отличие от v3-хендлера, здесь нет set_error_handler/ini_set/exit и
 * отправки сообщений: только форматирование, которое можно использовать
 * из любого места и скомпоновать с Monolog/Sentry.
 */
final class ErrorFormatter
{
    /** @var list<string> */
    private array $tracePathFilters = [];

    private bool $shortTrace = false;

    public function __construct(
        private readonly string $appTitle = 'App',
        private readonly int $maxFrames = 6,
        private readonly int $snippetRadius = 1,
        private readonly int $maxVkLength = 3500,
        private readonly ?string $basePath = null,
    ) {}

    /**
     * Оставлять только кадры, содержащие одну из подстрок (как v3).
     */
    public function withTracePathFilter(string ...$substrings): self
    {
        $clone = clone $this;
        $clone->tracePathFilters = \array_values(\array_filter($substrings, static fn(string $s): bool => $s !== ''));
        $clone->shortTrace = true;

        return $clone;
    }

    public function shortTrace(): self
    {
        $clone = clone $this;
        $clone->shortTrace = true;

        return $clone;
    }

    public function format(\Throwable $e, string $context = ''): ErrorReport
    {
        $frames = $this->frames($e);
        $plain = $this->render($e, $frames, $context, false);
        $console = $this->render($e, $frames, $context, true);
        $vk = $this->renderVk($e, $frames, $context);

        return new ErrorReport(plain: $plain, console: $console, vk: $vk, fingerprint: $this->fingerprint($e));
    }

    /**
     * @return list<array{file: ?string, line: ?int, class: string, function: string, first: bool}>
     */
    private function frames(\Throwable $e): array
    {
        $raw = [[
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'class' => '',
            'function' => '{main}',
        ]];

        foreach ($e->getTrace() as $frame) {
            $raw[] = [
                'file' => isset($frame['file']) ? (string) $frame['file'] : null,
                'line' => isset($frame['line']) ? (int) $frame['line'] : null,
                'class' => isset($frame['class']) ? (string) $frame['class'] : '',
                'function' => (string) ($frame['function'] ?? '{unknown}'),
            ];
        }

        $filtered = $this->filterFrames($raw);
        $result = [];
        $seen = [];

        foreach ($filtered as $index => $frame) {
            $key = ($frame['file'] ?? '?') . ':' . ($frame['line'] ?? '?');
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $result[] = $frame + ['first' => $index === 0];

            if (\count($result) >= $this->maxFrames) {
                break;
            }
        }

        return $result;
    }

    /**
     * @param list<array{file: ?string, line: ?int, class: string, function: string}> $raw
     *
     * @return list<array{file: ?string, line: ?int, class: string, function: string}>
     */
    private function filterFrames(array $raw): array
    {
        if ($this->tracePathFilters !== []) {
            $matched = \array_values(\array_filter($raw, fn(array $frame): bool => $this->matchesFilter(
                $frame['file'],
            )));

            return $matched !== [] ? $matched : [$raw[0]];
        }

        $user = \array_values(\array_filter($raw, fn(array $frame): bool => !$this->isInternalPath($frame['file'])));

        return $user !== [] ? $user : [$raw[0]];
    }

    private function matchesFilter(?string $file): bool
    {
        if ($file === null || $this->tracePathFilters === []) {
            return false;
        }

        $normalized = \str_replace('\\', '/', $file);

        foreach ($this->tracePathFilters as $filter) {
            if (\str_contains($normalized, \str_replace('\\', '/', $filter))) {
                return true;
            }
        }

        return false;
    }

    private function isInternalPath(?string $file): bool
    {
        if ($file === null) {
            return true;
        }

        $normalized = \str_replace('\\', '/', $file);
        $libraryRoot = \str_replace('\\', '/', \dirname(__DIR__, 2));

        return \str_contains($normalized, '/vendor/') || \str_starts_with($normalized, $libraryRoot . '/src/');
    }

    /**
     * @param list<array{file: ?string, line: ?int, class: string, function: string, first: bool}> $frames
     */
    private function render(\Throwable $e, array $frames, string $context, bool $color): string
    {
        $message = $this->redact($e->getMessage());
        $header = \sprintf('%s: %s', $e::class, $message);
        if ($context !== '') {
            $header .= " [{$context}]";
        }

        $lines = [
            $color ? $this->colored('‼ ' . $header, 'red') : '‼ ' . $header,
        ];

        foreach ($frames as $frame) {
            $location = $this->location($frame['file'], $frame['line']);
            $call = $frame['class'] !== '' ? "{$frame['class']}->{$frame['function']}()" : "{$frame['function']}()";

            $lines[] = $color
                ? $this->colored('  ↳ ', 'green') . $this->colored($location, 'blue') . ' ' . $call
                : "  ↳ {$location} {$call}";

            if ($this->snippetRadius > 0) {
                $snippet = $this->snippet($frame['file'], $frame['line'], $color);
                if ($snippet !== '') {
                    $lines[] = $snippet;
                }
            }
        }

        if ($e->getPrevious() instanceof \Throwable) {
            $prev = $e->getPrevious();
            $lines[] = \sprintf(
                '  ⬑ предыдущее: %s (%s)',
                $this->redact($prev->getMessage()),
                $this->location($prev->getFile(), $prev->getLine()),
            );
        }

        return \implode("\n", $lines);
    }

    /**
     * @param list<array{file: ?string, line: ?int, class: string, function: string, first: bool}> $frames
     */
    private function renderVk(\Throwable $e, array $frames, string $context): string
    {
        $place = $frames[0]['file'] ?? $e->getFile();
        $line = $frames[0]['line'] ?? $e->getLine();

        $text = \sprintf(
            "⚠️ %s: ошибка%s\n%s\n📍 %s",
            $this->appTitle,
            $context !== '' ? " [{$context}]" : '',
            $this->redact($e->getMessage()),
            $this->location($place, $line),
        );

        return $this->truncate($text, $this->maxVkLength);
    }

    private function location(?string $file, ?int $line): string
    {
        if ($file === null) {
            return '[internal]';
        }

        $path = $file;
        if ($this->basePath !== null && $this->basePath !== '') {
            $path = \str_replace(\str_replace('\\', '/', $this->basePath), '.', \str_replace('\\', '/', $path));
        }

        return $line !== null ? "{$path}:{$line}" : $path;
    }

    private function snippet(?string $file, ?int $line, bool $color): string
    {
        if ($file === null || $line === null || !\is_readable($file)) {
            return '';
        }

        $size = @\filesize($file);
        if ($size === false || $size > (512 * 1024)) {
            return '';
        }

        $source = @\file($file, \FILE_IGNORE_NEW_LINES);
        if ($source === false) {
            return '';
        }

        $start = \max(0, $line - $this->snippetRadius - 1);
        $end = \min(\count($source), $line + $this->snippetRadius);

        $lines = [];
        for ($i = $start; $i < $end; $i++) {
            $number = $i + 1;
            $code = $this->stripAnsi($source[$i]);
            $prefix = $number === $line ? '>' : ' ';
            $lineText = \sprintf('%s %4d | %s', $prefix, $number, $code);

            $lines[] = $number === $line
                ? ($color ? $this->colored($lineText, 'yellow') : $lineText)
                : ($color ? $this->colored($lineText, 'gray') : $lineText);
        }

        return \implode("\n", $lines);
    }

    private function redact(string $text): string
    {
        $patterns = [
            '/((?:access_token|refresh_token|token|sign|password|secret|api_key|apikey|authorization|launch_params|dsn)\s*[=:]\s*)([^\s&,;]+)/iu',
            '/([?&](?:access_token|token|sign|key|api_key)=)([^&\s]+)/iu',
        ];

        foreach ($patterns as $pattern) {
            $replaced = \preg_replace($pattern, '$1***', $text);
            if ($replaced !== null) {
                $text = $replaced;
            }
        }

        return $text;
    }

    private function truncate(string $text, int $limit): string
    {
        if (\mb_strlen($text) <= $limit) {
            return $text;
        }

        return \mb_substr($text, 0, \max(0, $limit - 1)) . '…';
    }

    private function fingerprint(\Throwable $e): string
    {
        $message = \preg_replace('/\d+/', '#', $e->getMessage()) ?? $e->getMessage();

        return \sha1($e::class . '|' . $message . '|' . $e->getFile() . ':' . $e->getLine());
    }

    private function colored(string $text, string $color): string
    {
        $codes = [
            'red' => "\033[31m",
            'green' => "\033[32m",
            'yellow' => "\033[33m",
            'blue' => "\033[34m",
            'gray' => "\033[90m",
        ];

        return ($codes[$color] ?? '') . $text . "\033[0m";
    }

    private function stripAnsi(string $text): string
    {
        return (string) \preg_replace('/\033\[[0-9;]*m/', '', $text);
    }
}
