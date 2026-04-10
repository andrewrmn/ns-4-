<?php

namespace modules\neuroselect;

use craft\helpers\App;

/**
 * Which backend generates HTML→PDF for PIR and Neuro Q survey flows.
 *
 * Env PIR_PDF_ENGINE: chromium | pdfshift | wkhtmltopdf | dompdf
 * If unset: chromium when a Chrome/Chromium binary is available, else pdfshift when
 * PDFSHIFT_API_KEY or PIR_PDFSHIFT_API_KEY is set, else dompdf.
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

        // Chromium needs a subprocess; many hosts disable exec() but leave proc_open() enabled.
        $canRunChromium = ChromiumPdfRenderer::binaryPath() !== null && SafeProcess::canRunSubprocess();
        if ($canRunChromium) {
            return self::CHROMIUM;
        }
        if (PdfShiftRenderer::isConfigured()) {
            return self::PDFSHIFT;
        }

        return self::DOMPDF;
    }
}
