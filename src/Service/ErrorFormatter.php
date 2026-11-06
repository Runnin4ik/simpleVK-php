<?php

declare(strict_types=1);

namespace DigitalStars\SimpleVK\Service;

/**
 * Форматтер в стиле v3 ErrorHandler: FATAL-заголовок, кадры с номерами,
 * маркером ➡ для пользовательских файлов и строкой кода из источника.
 *
 * Без глобального состояния и без побочных эффектов: только строки для
 * консоли, лога и VK. Цвета включаются отдельно, поэтому один и тот же
 * результат можно писать в терминал и в journald.
 */
final class ErrorFormatter
{
    /** @var list<string> */
    private array $tracePathFilters = [];

    private bool $shortTrace = false;

    public function __construct(
        private readonly string $appTitle = 'App',
        private readonly int $maxFrames = 50,
        private readonly int $snippetPadding = 0,
        private readonly int $maxVkLength = 0,
        private readonly ?string $basePath = null,
    ) {}

    /**
     * Пути, которые в trace заменяются на `..` (как v3 setTracePathFilter).
     */
    public function withTracePathFilter(string ...$substrings): self
    {
        $clone = clone $this;
        $clone->tracePathFilters = \array_values(\array_filter($substrings, static fn(string $s): bool => $s !== ''));

        return $clone;
    }

    /**
     * Скрыть vendor/внутренние кадры, как v3 shortTrace().
     */
    public function shortTrace(): self
    {
        $clone = clone $this;
        $clone->shortTrace = true;

        return $clone;
    }

    public function format(\Throwable $e, string $context = ''): ErrorReport
    {
        $frames = $this->frames($e);

        // Контекст в v3-формат не входил; оставляем его только в PSR-3
        // контексте ErrorReporter, чтобы визуально не отличаться от v3.
        $plain = $this->render($e, $frames, false, false);
        $console = $this->render($e, $frames, true, false);
        $vk = $this->render($e, $frames, false, true);

        return new ErrorReport(
            plain: $plain,
            console: $console,
            vk: $this->truncate($vk),
            fingerprint: $this->fingerprint($e),
        );
    }

    /**
     * @return list<array{file: ?string, line: ?int, class: string, function: string, internal: bool, user: bool}>
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

        $result = [];
        foreach ($raw as $i => $frame) {
            $internal = $frame['file'] === null;
            $user = !$internal && !$this->isInternalPath($frame['file']);

            if ($this->shortTrace) {
                if (!$internal && !$user) {
                    continue;
                }

                if ($internal) {
                    $caller = $raw[$i + 1] ?? null;
                    if ($caller === null || $this->isInternalPath($caller['file'])) {
                        continue;
                    }
                }
            }

            $result[] = $frame + ['internal' => $internal, 'user' => $user];

            if (\count($result) >= $this->maxFrames) {
                break;
            }
        }

        return (
            $result !== []
                ? $result
                : [[
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'class' => '',
                    'function' => '{main}',
                    'internal' => false,
                    'user' => true,
                ]]
        );
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
     * @param list<array{file: ?string, line: ?int, class: string, function: string, internal: bool, user: bool}> $frames
     */
    private function render(\Throwable $e, array $frames, bool $color, bool $shortenPaths): string
    {
        $header = $this->formatErrorLevel($e, $color);
        $header .= $this->redact($e->getMessage());

        $trace = '';
        foreach ($frames as $index => $frame) {
            if ($frame['internal']) {
                $call = $frame['class'] !== '' ? "{$frame['class']}->{$frame['function']}()" : "{$frame['function']}()";

                $trace .=
                    $this->log('#' . ($index + 1) . ' ', 'green', $color)
                    . $this->log('[internal function]', 'blue', $color)
                    . "\n"
                    . $this->log('?: ', 'yellow', $color)
                    . $this->log($call, 'white', $color)
                    . "\n\n";

                continue;
            }

            $path = $this->path($frame['file'], $shortenPaths);
            $marker = $frame['user'] ? '➡ ' : '';
            $trace .=
                $this->log("{$marker}#" . ($index + 1) . ' ', 'green', $color)
                . $this->log($path, 'blue', $color)
                . $this->log(':' . ($frame['line'] ?? '?'), 'yellow', $color)
                . "\n";

            $snippet = $this->snippet($frame['file'], $frame['line'], $color);
            $trace .= $snippet !== '' ? $this->log($snippet, 'white', $color) . "\n" : "\n";
        }

        if ($e->getPrevious() instanceof \Throwable) {
            $prev = $e->getPrevious();
            $trace .=
                $this->log('⬑ предыдущее: ', 'yellow', $color)
                . $this->redact($prev->getMessage())
                . ' ('
                . $this->path($prev->getFile(), $shortenPaths)
                . ':'
                . $prev->getLine()
                . ")\n";
        }

        return $header . "\n\n" . $trace;
    }

    private function path(?string $file, bool $shortenPaths): string
    {
        if ($file === null) {
            return '?';
        }

        $path = \str_replace('\\', '/', $file);

        if ($shortenPaths) {
            foreach ($this->tracePathFilters as $filter) {
                $path = \str_replace($filter, '..', $path);
            }
        }

        return $path;
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

        $start = \max(0, $line - $this->snippetPadding - 1);
        $end = \min(\count($source), $line + $this->snippetPadding);

        $parts = [];
        for ($i = $start; $i < $end; $i++) {
            $number = ($i + 1) . ': ';
            $code = \trim($this->stripAnsi($source[$i]));

            $parts[] = $color
                ? $this->log($number, 'yellow', true) . $this->log($code, 'white', true)
                : $number . $code;
        }

        return $parts !== [] ? \implode(\PHP_EOL, $parts) . \PHP_EOL : '';
    }

    private function formatErrorLevel(\Throwable $e, bool $color): string
    {
        $type = $e instanceof \ErrorException ? $e->getSeverity() : \E_ERROR;

        [$level] = [
            \E_ERROR => ['CRITICAL'],
            \E_WARNING => ['WARNING'],
            \E_PARSE => ['ERROR'],
            \E_NOTICE => ['NOTICE'],
            \E_CORE_ERROR => ['CRITICAL'],
            \E_CORE_WARNING => ['WARNING'],
            \E_COMPILE_ERROR => ['CRITICAL'],
            \E_COMPILE_WARNING => ['WARNING'],
            \E_USER_ERROR => ['ERROR'],
            \E_USER_WARNING => ['WARNING'],
            \E_USER_NOTICE => ['NOTICE'],
            \E_RECOVERABLE_ERROR => ['ERROR'],
            \E_DEPRECATED => ['NOTICE'],
            \E_USER_DEPRECATED => ['NOTICE'],
        ][$type] ?? ['UNKNOWN'];

        $text = match ($level) {
            'ERROR', 'CRITICAL' => '‼Fatal Error: ',
            'WARNING' => '⚠️Warning: ',
            'NOTICE' => '⚠️Notice: ',
            default => '‼Unknown Error: ',
        };

        if (!$color) {
            return $text;
        }

        $colorCode = match ($level) {
            'ERROR', 'CRITICAL' => 'red',
            'WARNING' => 'yellow',
            'NOTICE' => 'blue',
            default => 'red',
        };

        return $this->log($text, $colorCode, true);
    }

    private function log(string $text, string $color, bool $enabled): string
    {
        if (!$enabled) {
            return $text;
        }

        $codes = [
            'red' => "\033[31m",
            'green' => "\033[32m",
            'yellow' => "\033[33m",
            'blue' => "\033[34m",
            'white' => "\033[37m",
        ];

        return ($codes[$color] ?? '') . $text . "\033[0m";
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

    private function truncate(string $text): string
    {
        if ($this->maxVkLength <= 0 || \mb_strlen($text) <= $this->maxVkLength) {
            return $text;
        }

        return \mb_substr($text, 0, \max(0, $this->maxVkLength - 1)) . '…';
    }

    private function fingerprint(\Throwable $e): string
    {
        $message = \preg_replace('/\d+/', '#', $e->getMessage()) ?? $e->getMessage();

        return \sha1($e::class . '|' . $message . '|' . $e->getFile() . ':' . $e->getLine());
    }

    private function stripAnsi(string $text): string
    {
        return (string) \preg_replace('/\033\[[0-9;]*m/', '', $text);
    }
}
