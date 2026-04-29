<?php

namespace modules\neuroselect;

/**
 * PIR and Neuro Q survey PDFs use PDFShift only (no Chromium / wkhtmltopdf / Dompdf).
 */
final class PdfGenerationEngine
{
    public const PDFSHIFT = 'pdfshift';

    public static function engineId(): string
    {
        return self::PDFSHIFT;
    }
}
