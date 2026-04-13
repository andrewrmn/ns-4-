<?php

namespace modules\neuroselect;

use craft\helpers\App;

/**
 * Which backend generates HTML→PDF for PIR and Neuro Q survey flows.
 *
 * Env PIR_PDF_ENGINE: chromium | pdfshift | wkhtmltopdf | dompdf (explicit value always wins).
 * If unset: PDFShift when PDFSHIFT_API_KEY or PIR_PDFSHIFT_API_KEY is set (even if Chrome exists),
 * else Chromium when a binary is available, else Dompdf.
 */
final class PdfGenerationEngine
{
    public const CHROMIUM = 'chromium';

    public const PDFSHIFT = 'pdfshift';

    public const WKHTML = 'wkhtmltopdf';

    public const DOMPDF = 'dompdf';

    public static function engineId(): string
    {
        $e = App::env('PIR_PDF_ENGINE');
        if (is_string($e) && trim($e) !== '') {
            return strtolower(trim($e));
        }

        // Prefer PDFShift whenever configured so production (no Chrome) and staging match; local Mac
        // otherwise picked Chromium first and skipped PDFShift.
        if (PdfShiftRenderer::isConfigured()) {
            return self::PDFSHIFT;
        }

        $canRunChromium = ChromiumPdfRenderer::binaryPath() !== null && SafeProcess::canRunSubprocess();
        if ($canRunChromium) {
            return self::CHROMIUM;
        }

        return self::DOMPDF;
    }
}
