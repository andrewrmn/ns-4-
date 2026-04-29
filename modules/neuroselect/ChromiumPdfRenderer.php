<?php

namespace modules\neuroselect;

use Craft;
use craft\helpers\App;

/**
 * Headless Chrome/Chromium — same rendering pipeline as Chrome “Print → Save as PDF”.
 * Use this when Dompdf layout is not close enough to the on-screen page.
 */
final class ChromiumPdfRenderer
{
    /**
     * Prefer CHROMIUM_BIN / CHROME_BIN, then common paths, then PATH (google-chrome, chromium, …).
     */
    public static function binaryPath(): ?string
    {
        $fromEnv = self::envString('CHROMIUM_BIN') ?: self::envString('CHROME_BIN');
        if ($fromEnv !== '' && @is_executable($fromEnv)) {
            return $fromEnv;
        }

        $candidates = [
            '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
            '/Applications/Chromium.app/Contents/MacOS/Chromium',
            '/opt/google/chrome/chrome',
            '/usr/bin/google-chrome',
            '/usr/bin/google-chrome-stable',
            '/usr/local/bin/google-chrome',
            '/usr/local/bin/google-chrome-stable',
            '/usr/bin/chromium',
            '/usr/bin/chromium-browser',
            '/snap/bin/chromium',
        ];
        foreach ($candidates as $path) {
            if (@is_executable($path)) {
                return $path;
            }
        }

        // PATH lookup: shell_exec when allowed; else proc_open + /bin/sh (Cloudways often disables shell_exec only).
        if (\function_exists('shell_exec')) {
            foreach (['google-chrome', 'google-chrome-stable', 'chromium', 'chromium-browser'] as $name) {
                $which = trim((string) @\shell_exec('command -v ' . \escapeshellarg($name) . ' 2>/dev/null'));
                if ($which !== '' && @\is_executable($which)) {
                    return $which;
                }
            }
        } elseif (SafeProcess::canRunSubprocess()) {
            $sh = 'for n in google-chrome-stable google-chrome chromium chromium-browser; do '
                . 'p=$(command -v "$n" 2>/dev/null); [ -n "$p" ] && [ -x "$p" ] && echo "$p" && exit 0; '
                . 'done; exit 0';
            [$lines, ] = SafeProcess::run('/bin/sh -c ' . \escapeshellarg($sh));
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line !== '' && $line[0] === '/' && @\is_executable($line)) {
                    return $line;
                }
            }
        }

        return null;
    }

    /**
     * @param string|null $errorDetail set on failure
     */
    public static function renderUrlToPdf(string $url, ?string &$errorDetail = null): string|false
    {
        $errorDetail = null;

        if (!SafeProcess::canRunSubprocess()) {
            $errorDetail = 'PHP exec() and proc_open() are unavailable. PIR/survey PDFs use PDFShift; this path is not used for those flows.';

            return false;
        }

        $bin = self::binaryPath();
        if ($bin === null) {
            $errorDetail = 'Chromium/Chrome not found. PIR/survey PDFs use PDFShift; install Chrome or set CHROMIUM_BIN only if you call this renderer directly.';

            return false;
        }

        $outPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'craft-chrome-pdf-' . uniqid('', true) . '.pdf';

        $flags = [
            '--headless=new',
            '--disable-gpu',
            '--hide-scrollbars',
            '--no-pdf-header-footer',
        ];

        if (filter_var(App::env('PIR_CHROMIUM_NO_SANDBOX') ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $flags[] = '--no-sandbox';
            $flags[] = '--disable-setuid-sandbox';
        }

        $budgetRaw = App::env('CHROMIUM_VIRTUAL_TIME_BUDGET_MS');
        $budget = is_numeric($budgetRaw) ? (int) $budgetRaw : 10000;
        if ($budget > 0) {
            $flags[] = '--virtual-time-budget=' . $budget;
        }

        $cmd = escapeshellarg($bin);
        foreach ($flags as $f) {
            $cmd .= ' ' . $f;
        }
        $cmd .= ' --print-to-pdf=' . escapeshellarg($outPath);
        $cmd .= ' ' . escapeshellarg($url);

        [$output, $exitCode] = SafeProcess::run($cmd . ' 2>&1');

        if ($exitCode !== 0 || !is_file($outPath)) {
            $tail = trim(implode("\n", array_slice($output, -8)));
            Craft::warning('Chromium PDF exit ' . $exitCode . ' — ' . $tail, __METHOD__);
            @unlink($outPath);
            $errorDetail = $tail !== '' ? $tail : ('Exit code ' . $exitCode . '.');

            return false;
        }

        $pdf = file_get_contents($outPath);
        @unlink($outPath);

        if ($pdf === false || $pdf === '') {
            $errorDetail = 'Chromium wrote an empty PDF.';

            return false;
        }

        return $pdf;
    }

    /**
     * Use App::env (reads $_SERVER) — not raw getenv(), which is empty on many FPM pools with putenv disabled.
     */
    private static function envString(string $key): string
    {
        $v = App::env($key);
        if (is_string($v) && $v !== '') {
            return $v;
        }
        if (isset($_ENV[$key]) && is_string($_ENV[$key]) && $_ENV[$key] !== '') {
            return $_ENV[$key];
        }

        return '';
    }
}
