<?php

namespace modules\neuroselect;

use Craft;
use craft\helpers\App;
use GuzzleHttp\Exception\GuzzleException;

/**
 * HTML→PDF via PDFShift (https://pdfshift.io) — Chromium-based, no local binary.
 *
 * Env: PDFSHIFT_API_KEY or PIR_PDFSHIFT_API_KEY (required)
 * PIR_PDFSHIFT_SANDBOX: defaults to true (watermarked, no credits). Set to false for live conversions.
 */
final class PdfShiftRenderer
{
    private const ENDPOINT = 'https://api.pdfshift.io/v3/convert/pdf';

    /**
     * PDFShift sandbox mode: same API key; adds watermark and does not consume credits.
     * Defaults ON so staging/dev do not burn credits. Opt out with PIR_PDFSHIFT_SANDBOX=false.
     */
    public static function useSandbox(): bool
    {
        $v = App::env('PIR_PDFSHIFT_SANDBOX');
        if ($v === null || $v === '') {
            return true;
        }

        return filter_var($v, FILTER_VALIDATE_BOOLEAN);
    }

    public static function isConfigured(): bool
    {
        $k = self::apiKey();

        return $k !== null && $k !== '';
    }

    public static function apiKey(): ?string
    {
        foreach (['PDFSHIFT_API_KEY', 'PIR_PDFSHIFT_API_KEY'] as $name) {
            $v = App::env($name);
            if (is_string($v) && $v !== '') {
                return $v;
            }
        }

        return null;
    }

    /**
     * @param string|null $extraCss Appended by PDFShift before print (e.g. merged pdf9 + dompdf tweaks)
     * @param string|null $errorDetail set on failure
     */
    public static function renderUrlToPdf(
        string $url,
        string $footerInnerHtml,
        ?string $headerInnerHtml,
        ?string $extraCss,
        ?string &$errorDetail = null
    ): string|false {
        $errorDetail = null;
        $key = self::apiKey();
        if ($key === null || $key === '') {
            $errorDetail = 'PDFShift API key missing. Set PDFSHIFT_API_KEY or PIR_PDFSHIFT_API_KEY.';

            return false;
        }

        $payload = [
            'source' => $url,
            'format' => 'A4',
            'use_print' => true,
            'disable_javascript' => true,
            'timeout' => 60,
        ];

        if ($footerInnerHtml !== '') {
            $payload['footer'] = [
                'source' => $footerInnerHtml,
                'height' => '80',
            ];
        }
        if ($headerInnerHtml !== null && $headerInnerHtml !== '') {
            $payload['header'] = [
                'source' => $headerInnerHtml,
                'height' => '48',
            ];
        }
        if ($extraCss !== null && $extraCss !== '') {
            $payload['css'] = $extraCss;
        }
        if (self::useSandbox()) {
            $payload['sandbox'] = true;
        }

        $processor = App::env('PIR_PDFSHIFT_PROCESSOR_VERSION');
        $headers = [
            'X-API-Key' => $key,
            'Content-Type' => 'application/json',
            'Accept' => 'application/pdf, application/json',
        ];
        if (is_string($processor) && ($processor === '116' || $processor === '142')) {
            $headers['X-Processor-Version'] = $processor;
        }

        try {
            $client = Craft::createGuzzleClient([
                'timeout' => 180,
                'connect_timeout' => 30,
                'http_errors' => false,
            ]);
            $response = $client->post(self::ENDPOINT, [
                'headers' => $headers,
                'json' => $payload,
            ]);
        } catch (GuzzleException $e) {
            Craft::warning('PDFShift: ' . $e->getMessage(), __METHOD__);
            $errorDetail = 'PDFShift request failed.';

            return false;
        }

        $code = $response->getStatusCode();
        $body = (string) $response->getBody();
        $ct = strtolower($response->getHeaderLine('Content-Type'));

        if ($code >= 200 && $code < 300 && strncmp($body, '%PDF', 4) === 0) {
            return $body;
        }

        if (str_contains($ct, 'application/pdf') && strncmp($body, '%PDF', 4) === 0) {
            return $body;
        }

        $msg = 'PDFShift HTTP ' . $code;
        if (str_contains($ct, 'json')) {
            $j = json_decode($body, true);
            if (is_array($j)) {
                if (isset($j['errors']) && is_array($j['errors'])) {
                    $msg .= ': ' . json_encode($j['errors']);
                } elseif (isset($j['message'])) {
                    $msg .= ': ' . (string) $j['message'];
                } elseif (isset($j['error'])) {
                    $msg .= ': ' . (is_string($j['error']) ? $j['error'] : json_encode($j['error']));
                }
            }
        } elseif ($body !== '') {
            $msg .= ': ' . substr($body, 0, 300);
        }

        Craft::warning($msg, __METHOD__);
        $errorDetail = trim($msg);

        return false;
    }
}
