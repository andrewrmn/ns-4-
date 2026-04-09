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
     * @param string|null $stylesheetUrl external stylesheet URL (&lt;link&gt;); ignored if $stylesheetInline is non-empty
     * @param string|null $stylesheetInline raw CSS injected in &lt;style&gt; (e.g. read from @webroot/css/pdf9.css)
     * @param string|null $errorDetail set on failure for JSON / logs
     */
    public static function fromUrl(
        string $url,
        ?string $stylesheetUrl,
        ?string $headerInnerHtml,
        ?string $footerInnerHtml,
        ?string $stylesheetInline = null,
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

        $baseHref = self::baseHrefFromUrl($url);
        $html = self::injectHeadAndBodyWrappers(
            $html,
            $baseHref,
            $stylesheetUrl,
            $stylesheetInline,
            $headerInnerHtml,
            $footerInnerHtml
        );

        $options = new Options();
        $options->set('isRemoteEnabled', true);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'Helvetica');

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
}
