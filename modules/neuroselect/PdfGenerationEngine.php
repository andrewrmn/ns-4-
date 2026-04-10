<?php

namespace modules\neuroselect;

/**
 * Which backend generates HTML→PDF for PIR and Neuro Q survey flows.
 *
 * Env PIR_PDF_ENGINE: chromium | wkhtmltopdf | dompdf
 * If unset: use chromium when a Chrome/Chromium binary is detected, otherwise dompdf.
 */
final class PdfGenerationEngine
{
    public const CHROMIUM = 'chromium';

    public const WKHTML = 'wkhtmltopdf';

    public const DOMPDF = 'dompdf';

    public static function engineId(): string
    {
        $e = getenv('PIR_PDF_ENGINE');
        if (is_string($e) && trim($e) !== '') {
            return strtolower(trim($e));
        }

        // Chromium needs a subprocess; many hosts disable exec() but leave proc_open() enabled.
        $canRunChromium = ChromiumPdfRenderer::binaryPath() !== null && SafeProcess::canRunSubprocess();

        return $canRunChromium ? self::CHROMIUM : self::DOMPDF;
    }
}
