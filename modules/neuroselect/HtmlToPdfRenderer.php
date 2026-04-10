<?php

namespace modules\neuroselect;

use Craft;
use Dompdf\Dompdf;
use Dompdf\Options;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Renders a public report URL to PDF using Dompdf (no paid API, no external binary).
 * Fetches HTML over HTTP, resolves relative assets via &lt;base href&gt;, applies print CSS, wraps optional header/footer.
 */
final class HtmlToPdfRenderer
{
    /**
     * @param string|null $stylesheetUrl HTTP(S) URL or absolute filesystem path to CSS (fetched/read and inlined for Dompdf)
     * @param string|null $stylesheetInline raw CSS injected in &lt;style&gt; (takes precedence over URL when non-empty)
     * @param string|null $appendStylesheetInline extra CSS appended after resolved sheet (e.g. pdf9-dompdf.css)
     * @param string|null $errorDetail set on failure for JSON / logs
     */
    public static function fromUrl(
        string $url,
        ?string $stylesheetUrl,
        ?string $headerInnerHtml,
        ?string $footerInnerHtml,
        ?string $stylesheetInline = null,
        ?string $appendStylesheetInline = null,
        ?string &$errorDetail = null
    ): string|false {
        $errorDetail = null;

        try {
            $client = Craft::createGuzzleClient([
                'timeout' => 120,
                'connect_timeout' => 30,
                'http_errors' => false,
            ]);
            $response = $client->get($url);
            $code = $response->getStatusCode();
            if ($code < 200 || $code >= 300) {
                $errorDetail = "Failed to fetch report page (HTTP {$code}).";

                return false;
            }
            $html = (string)$response->getBody();
        } catch (GuzzleException $e) {
            Craft::warning($e->getMessage(), __METHOD__);
            $errorDetail = 'Could not load the report URL.';

            return false;
        }

        if (trim($html) === '') {
            $errorDetail = 'Report page was empty.';

            return false;
        }

        $resolvedCss = self::resolvePrintStylesheet($stylesheetUrl, $stylesheetInline);
        if ($appendStylesheetInline !== null && $appendStylesheetInline !== '') {
            $resolvedCss = $resolvedCss === ''
                ? $appendStylesheetInline
                : $resolvedCss . "\n" . $appendStylesheetInline;
        }
        if ($resolvedCss === '' && ($stylesheetUrl !== null && $stylesheetUrl !== '')) {
            Craft::warning(
                'Dompdf print stylesheet could not be loaded (empty after fetch): ' . $stylesheetUrl,
                __METHOD__
            );
        }

        $baseHref = self::baseHrefFromUrl($url);
        $html = self::injectHeadAndBodyWrappers(
            $html,
            $baseHref,
            null,
            $resolvedCss !== '' ? $resolvedCss : null,
            $headerInnerHtml,
            $footerInnerHtml
        );

        $options = new Options();
        $options->set('isRemoteEnabled', true);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'Helvetica');
        // Slightly taller line boxes than default — reduces cramped/overlapping text in CPDF.
        $options->set('fontHeightRatio', 1.15);

        try {
            $dompdf = new Dompdf($options);
            $dompdf->loadHtml($html, 'UTF-8');
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->render();

            return $dompdf->output();
        } catch (\Throwable $e) {
            Craft::warning('Dompdf: ' . $e->getMessage(), __METHOD__);
            $errorDetail = 'PDF rendering failed.';

            return false;
        }
    }

    private static function baseHrefFromUrl(string $url): string
    {
        $p = parse_url($url);
        if ($p === false || empty($p['scheme']) || empty($p['host'])) {
            return '';
        }
        $auth = '';
        if (!empty($p['user'])) {
            $auth = $p['user'];
            if (!empty($p['pass'])) {
                $auth .= ':' . $p['pass'];
            }
            $auth .= '@';
        }
        $port = isset($p['port']) ? ':' . $p['port'] : '';
        $path = $p['path'] ?? '/';
        $dir = dirname($path);
        if ($dir === '.' || $dir === '/' || $dir === '\\') {
            $pathPart = '/';
        } else {
            $pathPart = str_replace('\\', '/', $dir) . '/';
        }

        return $p['scheme'] . '://' . $auth . $p['host'] . $port . $pathPart;
    }

    /**
     * Insert &lt;base&gt; right after &lt;head&gt; (for relative URLs).
     * Insert print/stylesheet last in &lt;head&gt; (before &lt;/head&gt;) so it wins over /css/main.css etc.
     * Prepend header and append footer inside &lt;body&gt;.
     */
    private static function injectHeadAndBodyWrappers(
        string $html,
        string $baseHref,
        ?string $stylesheetUrl,
        ?string $stylesheetInline,
        ?string $headerInnerHtml,
        ?string $footerInnerHtml
    ): string {
        $baseTag = '';
        if ($baseHref !== '') {
            $baseTag = '<base href="' . htmlspecialchars($baseHref, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '">';
        }

        $styleTag = '';
        if ($stylesheetInline !== null && $stylesheetInline !== '') {
            $styleTag = '<style type="text/css">' . str_ireplace('</style>', '', $stylesheetInline) . '</style>';
        } elseif ($stylesheetUrl !== null && $stylesheetUrl !== '') {
            $styleTag = '<link rel="stylesheet" href="'
                . htmlspecialchars($stylesheetUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '" media="all">';
        }

        if ($baseTag !== '') {
            if (preg_match('/<head\b[^>]*>/i', $html)) {
                $html = preg_replace_callback(
                    '/<head\b[^>]*>/i',
                    static fn (array $m) => $m[0] . $baseTag,
                    $html,
                    1
                );
            } elseif ($styleTag === '') {
                $html = '<!DOCTYPE html><html><head><meta charset="utf-8">' . $baseTag . '</head><body>'
                    . $html . '</body></html>';
            }
        }

        if ($styleTag !== '') {
            if (preg_match('/<\/head>/i', $html)) {
                $html = preg_replace('/<\/head>/i', $styleTag . '</head>', $html, 1);
            } elseif (preg_match('/<head\b[^>]*>/i', $html)) {
                $html = preg_replace_callback(
                    '/<head\b[^>]*>/i',
                    static fn (array $m) => $m[0] . $styleTag,
                    $html,
                    1
                );
            } else {
                $wrapHead = '<meta charset="utf-8">' . $baseTag . $styleTag;
                $html = '<!DOCTYPE html><html><head>' . $wrapHead . '</head><body>'
                    . $html . '</body></html>';
            }
        } elseif ($baseTag !== '' && !preg_match('/<head\b[^>]*>/i', $html)) {
            $html = '<!DOCTYPE html><html><head><meta charset="utf-8">' . $baseTag . '</head><body>'
                . $html . '</body></html>';
        }

        $header = $headerInnerHtml ?? '';
        $footer = $footerInnerHtml ?? '';
        if ($header === '' && $footer === '') {
            return $html;
        }

        if (preg_match('/<body\b[^>]*>/i', $html, $m, PREG_OFFSET_CAPTURE)) {
            $openEnd = $m[0][1] + strlen($m[0][0]);
            if (preg_match('/<\/body>/i', $html, $m2, PREG_OFFSET_CAPTURE, $openEnd)) {
                $closeStart = $m2[0][1];
                $inner = substr($html, $openEnd, $closeStart - $openEnd);
                $replacement = $header . $inner . $footer;
                $html = substr_replace($html, $replacement, $openEnd, $closeStart - $openEnd);
            } else {
                $html = substr_replace($html, $header . $footer, $openEnd, 0);
            }
        } else {
            $html = $header . $html . $footer;
        }

        return $html;
    }

    /**
     * Dompdf often fails to load &lt;link rel="stylesheet"&gt; from remote URLs on locked-down hosts.
     * Always inline: prefer $inline, else fetch/read $urlOrPath via Guzzle (http(s)) or filesystem.
     */
    private static function resolvePrintStylesheet(?string $urlOrPath, ?string $inline): string
    {
        if ($inline !== null && $inline !== '') {
            return $inline;
        }
        if ($urlOrPath === null || $urlOrPath === '') {
            return '';
        }

        $body = self::fetchStylesheet($urlOrPath);
        if ($body === '') {
            Craft::warning('Could not load stylesheet for Dompdf: ' . $urlOrPath, __METHOD__);
        }

        return $body;
    }

    private static function fetchStylesheet(string $urlOrPath): string
    {
        if (preg_match('#^https?://#i', $urlOrPath)) {
            try {
                $client = Craft::createGuzzleClient([
                    'timeout' => 60,
                    'connect_timeout' => 20,
                    'http_errors' => false,
                ]);
                $response = $client->get($urlOrPath);
                $code = $response->getStatusCode();
                if ($code >= 200 && $code < 300) {
                    return (string)$response->getBody();
                }
                Craft::warning("Stylesheet HTTP {$code}: {$urlOrPath}", __METHOD__);
            } catch (GuzzleException $e) {
                Craft::warning('Stylesheet fetch: ' . $e->getMessage(), __METHOD__);
            }

            return '';
        }

        if (is_file($urlOrPath)) {
            $c = @file_get_contents($urlOrPath);

            return is_string($c) ? $c : '';
        }

        return '';
    }
}
