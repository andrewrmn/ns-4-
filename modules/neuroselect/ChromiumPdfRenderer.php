<?php

namespace modules\neuroselect;

use Craft;

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
        $fromEnv = getenv('CHROMIUM_BIN') ?: getenv('CHROME_BIN');
        if (is_string($fromEnv) && $fromEnv !== '' && @is_executable($fromEnv)) {
            return $fromEnv;
        }

        $candidates = [
            '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
            '/Applications/Chromium.app/Contents/MacOS/Chromium',
            '/usr/bin/google-chrome',
            '/usr/bin/google-chrome-stable',
            '/usr/bin/chromium',
            '/usr/bin/chromium-browser',
        ];
        foreach ($candidates as $path) {
            if (@is_executable($path)) {
                return $path;
            }
        }

        // Many hosts (e.g. Cloudways) disable shell_exec; skip PATH lookup instead of fatalling.
        if (\function_exists('shell_exec')) {
            foreach (['google-chrome', 'google-chrome-stable', 'chromium', 'chromium-browser'] as $name) {
                $which = trim((string) @\shell_exec('command -v ' . \escapeshellarg($name) . ' 2>/dev/null'));
                if ($which !== '' && @\is_executable($which)) {
                    return $which;
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

        if (!\function_exists('exec')) {
            $errorDetail = 'PHP exec() is disabled on this server. Set PIR_PDF_ENGINE=dompdf or ask the host to allow exec for headless Chrome.';

            return false;
        }

        $bin = self::binaryPath();
        if ($bin === null) {
            $errorDetail = 'Chromium/Chrome not found. Install Chrome or set CHROMIUM_BIN, or use PIR_PDF_ENGINE=dompdf.';

            return false;
        }

        $outPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'craft-chrome-pdf-' . uniqid('', true) . '.pdf';

        $flags = [
            '--headless=new',
            '--disable-gpu',
            '--hide-scrollbars',
            '--no-pdf-header-footer',
        ];

        if (getenv('PIR_CHROMIUM_NO_SANDBOX')) {
            $flags[] = '--no-sandbox';
            $flags[] = '--disable-setuid-sandbox';
        }

        $budget = (int)(getenv('CHROMIUM_VIRTUAL_TIME_BUDGET_MS') ?: '10000');
        if ($budget > 0) {
            $flags[] = '--virtual-time-budget=' . $budget;
        }

        $cmd = escapeshellarg($bin);
        foreach ($flags as $f) {
            $cmd .= ' ' . $f;
        }
        $cmd .= ' --print-to-pdf=' . escapeshellarg($outPath);
        $cmd .= ' ' . escapeshellarg($url);

        $output = [];
        $exitCode = 1;
        \exec($cmd . ' 2>&1', $output, $exitCode);

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
}
