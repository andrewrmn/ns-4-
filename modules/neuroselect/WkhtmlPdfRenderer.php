<?php

namespace modules\neuroselect;

use Craft;
use craft\helpers\App;

final class WkhtmlPdfRenderer
{
    /**
     * @param string|null $errorDetail set on failure
     */
    public static function render(
        string $sourceUrl,
        string $footerInnerHtml,
        string $userStylesheetPathOrUrl,
        ?string &$errorDetail = null,
        ?string $headerInnerHtml = null
    ): string|false {
        $errorDetail = null;

        if (!SafeProcess::canRunSubprocess()) {
            $errorDetail = 'PHP exec() and proc_open() are unavailable. PIR/survey PDFs use PDFShift; this path is not used for those flows.';

            return false;
        }

        $b = App::env('WKHTMLTOPDF_BIN');
        $bin = (is_string($b) && $b !== '') ? $b : 'wkhtmltopdf';
        if (!is_string($bin) || $bin === '') {
            Craft::error('WKHTMLTOPDF_BIN is empty.', __METHOD__);
            $errorDetail = 'WKHTMLTOPDF_BIN is not configured.';

            return false;
        }

        $tmpDir = sys_get_temp_dir();
        $footerPath = $tmpDir . DIRECTORY_SEPARATOR . 'wk-footer-' . uniqid('', true) . '.html';
        $outPath = $tmpDir . DIRECTORY_SEPARATOR . 'wk-out-' . uniqid('', true) . '.pdf';

        $footerDoc = '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>' . $footerInnerHtml . '</body></html>';
        file_put_contents($footerPath, $footerDoc);

        $headerPath = null;
        if ($headerInnerHtml !== null && $headerInnerHtml !== '') {
            $headerPath = $tmpDir . DIRECTORY_SEPARATOR . 'wk-header-' . uniqid('', true) . '.html';
            $headerDoc = '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>' . $headerInnerHtml . '</body></html>';
            file_put_contents($headerPath, $headerDoc);
        }

        $args = [
            $bin,
            '--quiet',
            '--print-media-type',
            '--enable-local-file-access',
            '--footer-html',
            $footerPath,
            '--footer-spacing',
            '5',
        ];

        if ($headerPath !== null) {
            $args[] = '--header-html';
            $args[] = $headerPath;
            $args[] = '--header-spacing';
            $args[] = '5';
        }

        $args = array_merge($args, [
            '--user-style-sheet',
            $userStylesheetPathOrUrl,
            '--margin-top',
            '10mm',
            '--margin-bottom',
            '25mm',
            '--margin-left',
            '10mm',
            '--margin-right',
            '10mm',
            $sourceUrl,
            $outPath,
        ]);

        $cmd = '';
        foreach ($args as $i => $segment) {
            $cmd .= ($i > 0 ? ' ' : '') . escapeshellarg($segment);
        }

        [$output, $exitCode] = SafeProcess::run($cmd . ' 2>&1');

        @unlink($footerPath);
        if ($headerPath !== null) {
            @unlink($headerPath);
        }

        if ($exitCode !== 0 || !is_file($outPath)) {
            $tail = implode("\n", array_slice($output, -5));
            Craft::warning(
                'wkhtmltopdf exit ' . $exitCode . ' cmd: ' . $bin . ' … ' . $sourceUrl . ' — ' . implode("\n", $output),
                __METHOD__
            );
            @unlink($outPath);
            $errorDetail = $tail !== '' ? trim($tail) : ('Exit code ' . $exitCode . '.');

            return false;
        }

        $pdfBody = file_get_contents($outPath);
        @unlink($outPath);

        if ($pdfBody === false || $pdfBody === '') {
            return false;
        }

        return $pdfBody;
    }
}
