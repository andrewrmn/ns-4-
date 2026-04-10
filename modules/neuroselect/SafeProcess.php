<?php

namespace modules\neuroselect;

/**
 * Run shell commands when exec() is disabled but proc_open() is allowed (common on managed PHP-FPM).
 */
final class SafeProcess
{
    public static function canRunSubprocess(): bool
    {
        return \function_exists('exec') || \function_exists('proc_open');
    }

    /**
     * @return array{0: string[], 1: int} output lines and exit code
     */
    public static function run(string $commandLine): array
    {
        if (\function_exists('exec')) {
            $lines = [];
            $code = 0;
            @\exec($commandLine . ' 2>&1', $lines, $code);

            return [$lines, (int) $code];
        }
        if (\function_exists('proc_open')) {
            return self::runViaProcOpen($commandLine);
        }

        return [[], 1];
    }

    /**
     * @return array{0: string[], 1: int}
     */
    private static function runViaProcOpen(string $commandLine): array
    {
        $desc = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = @\proc_open($commandLine, $desc, $pipes, null, null);
        if (!\is_resource($process)) {
            return [['proc_open() failed to start process'], 1];
        }
        \fclose($pipes[0]);
        $stdout = (string) \stream_get_contents($pipes[1]);
        $stderr = (string) \stream_get_contents($pipes[2]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);
        $code = \proc_close($process);
        $merged = $stdout;
        if ($stderr !== '') {
            $merged .= ($merged !== '' ? "\n" : '') . $stderr;
        }
        $merged = \trim($merged);
        $lines = $merged === '' ? [] : \preg_split('/\r\n|\n|\r/', $merged);

        return [$lines, (int) $code];
    }
}
