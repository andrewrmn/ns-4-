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
 * PIR_PDFSHIFT_TIMEOUT: optional seconds (default = cap). Clamped to your PDFShift plan max (see TIMEOUT_CAP).
 * PIR_PDFSHIFT_TIMEOUT_CAP: optional override when PDFShift raises your account limit (default 100; standard plans reject >100s).
 * PIR_PDFSHIFT_IGNORE_LONG_POLLING: optional — only when wait_for_network is true (default false here); skips long poll wait.
 * PIR_PDFSHIFT_DISABLE_JAVASCRIPT: optional true — skips in-page scripts (GTM, etc.). Use if timeouts persist after matching legacy css/use_print options.
 * PIR_PDFSHIFT_USE_PRINT: optional — defaults false to match legacy neuroselect plugin (pdfshift v2 used use_print false). Set true for @media print.
 */
final class PdfShiftRenderer
{
    private const ENDPOINT = 'https://api.pdfshift.io/v3/convert/pdf';

    /** Standard PDFShift plans reject timeout > 100s unless support raises your account limit. */
    private const TIMEOUT_CAP_DEFAULT = 100;

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

    /**
     * Legacy plugin (PDFShift API v2) sent use_print false — faster/simpler than print CSS everywhere.
     */
    public static function usePrint(): bool
    {
        $v = App::env('PIR_PDFSHIFT_USE_PRINT');
        if ($v === null || $v === '') {
            return false;
        }

        return filter_var($v, FILTER_VALIDATE_BOOLEAN);
    }

    /** True when env requests disable_javascript on the PDFShift payload (normalizes typo-prone booleans). */
    public static function envDisablesJavascript(): bool
    {
        $v = App::env('PIR_PDFSHIFT_DISABLE_JAVASCRIPT');
        if ($v === null || $v === '') {
            return false;
        }

        return filter_var(trim((string) $v), FILTER_VALIDATE_BOOLEAN);
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

    private static function pdfshiftTimeoutCapSeconds(): int
    {
        $cap = App::env('PIR_PDFSHIFT_TIMEOUT_CAP');
        if (is_string($cap) && $cap !== '') {
            $c = (int) $cap;
            if ($c >= 1 && $c <= 900) {
                return $c;
            }
        }

        return self::TIMEOUT_CAP_DEFAULT;
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
            'use_print' => self::usePrint(),
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

        $cap = self::pdfshiftTimeoutCapSeconds();
        $timeoutEnv = App::env('PIR_PDFSHIFT_TIMEOUT');
        $t = (is_string($timeoutEnv) && $timeoutEnv !== '') ? (int) $timeoutEnv : $cap;
        $t = max(1, min($t, $cap));
        $payload['timeout'] = $t;

        $wfn = App::env('PIR_PDFSHIFT_WAIT_FOR_NETWORK');
        $waitForNetwork = is_string($wfn) && $wfn !== '' && filter_var($wfn, FILTER_VALIDATE_BOOLEAN);
        $payload['wait_for_network'] = $waitForNetwork;

        if ($waitForNetwork) {
            $ilp = App::env('PIR_PDFSHIFT_IGNORE_LONG_POLLING');
            if ($ilp === null || $ilp === '' || filter_var($ilp, FILTER_VALIDATE_BOOLEAN)) {
                $payload['ignore_long_polling'] = true;
            }
        }

        if (self::envDisablesJavascript()) {
            $payload['disable_javascript'] = true;
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

        $pdfshiftRequestStartedMs = (int) round(microtime(true) * 1000);

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
            $elapsedMs = (int) round(microtime(true) * 1000) - $pdfshiftRequestStartedMs;
            // #region agent log
            PdfDebugSessionLog::write('H_PIPELINE', __METHOD__, 'pipeline_4_pdfshift_guzzle_exception', [
                'pipeline_step' => '4_guzzle',
                'elapsed_ms' => $elapsedMs,
                'exc_class' => $e::class,
            ]);
            // #endregion

            return false;
        }

        $elapsedMs = (int) round(microtime(true) * 1000) - $pdfshiftRequestStartedMs;

        $code = $response->getStatusCode();
        $body = (string) $response->getBody();
        $ct = strtolower($response->getHeaderLine('Content-Type'));

        if ($code >= 200 && $code < 300 && strncmp($body, '%PDF', 4) === 0) {
            // #region agent log
            PdfDebugSessionLog::write('H_PIPELINE', __METHOD__, 'pipeline_4_pdfshift_http_ok', [
                'pipeline_step' => '4_http',
                'http_code' => $code,
                'elapsed_ms' => $elapsedMs,
                'pdf_bytes' => strlen($body),
                'source_host' => (string) (parse_url($url, PHP_URL_HOST) ?: ''),
            ]);
            // #endregion

            return $body;
        }

        if (str_contains($ct, 'application/pdf') && strncmp($body, '%PDF', 4) === 0) {
            // #region agent log
            PdfDebugSessionLog::write('H_PIPELINE', __METHOD__, 'pipeline_4_pdfshift_http_ok_alt', [
                'pipeline_step' => '4_http',
                'http_code' => $code,
                'elapsed_ms' => $elapsedMs,
                'pdf_bytes' => strlen($body),
                'source_host' => (string) (parse_url($url, PHP_URL_HOST) ?: ''),
            ]);
            // #endregion

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

        // #region agent log
        PdfDebugSessionLog::write('H_PWFN,H_DB,H_PIPELINE', __METHOD__, 'pdfshift_failure', [
            'http_code' => $code,
            'elapsed_ms' => $elapsedMs,
            'pipeline_step' => '4_http',
            'pdfshift_timeout_sec' => $payload['timeout'] ?? null,
            'wait_for_network' => $payload['wait_for_network'] ?? null,
            'use_print' => $payload['use_print'] ?? null,
            'ignore_long_polling' => $payload['ignore_long_polling'] ?? null,
            'has_css_extra' => ($payload['css'] ?? '') !== '',
            'disable_javascript_payload' => (bool) ($payload['disable_javascript'] ?? false),
            'env_disable_js_reads_true' => self::envDisablesJavascript(),
            'source_host' => (string) (parse_url($url, PHP_URL_HOST) ?: ''),
            'err_snip' => substr($msg, 0, 420),
        ]);
        // #endregion

        return false;
    }
}
