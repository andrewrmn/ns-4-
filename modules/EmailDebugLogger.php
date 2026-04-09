<?php

namespace modules;

use Craft;

/**
 * Debug NDJSON logger for email HTML image src analysis (session fceae0).
 */
final class EmailDebugLogger
{
    private const LOG_PATH = '/Users/andrewross/Sites/neuroscience-3/.cursor/debug-fceae0.log';

    public static function logRenderedEmail(string $location, string $templateHint, string $htmlBody): void
    {
        // #region agent log
        preg_match_all('/<img[^>]+src=["\']([^"\']+)["\']/', $htmlBody, $m);
        $srcs = $m[1] ?? [];
        $imgAnalysis = [];
        foreach ($srcs as $s) {
            $imgAnalysis[] = [
                'src' => strlen($s) > 200 ? substr($s, 0, 200) . '…' : $s,
                'isAbsoluteHttp' => (bool) preg_match('#^https?://#i', $s),
                'isProtocolRelative' => str_starts_with($s, '//'),
                'isRootRelative' => str_starts_with($s, '/') && !str_starts_with($s, '//'),
            ];
        }
        $payload = [
            'sessionId' => 'fceae0',
            'timestamp' => (int) round(microtime(true) * 1000),
            'location' => $location,
            'message' => 'email img src audit',
            'hypothesisId' => 'A,B,C,D,E',
            'data' => [
                'templateHint' => $templateHint,
                'currentSiteBaseUrl' => Craft::$app->getSites()->getCurrentSite()->getBaseUrl() ?: '(empty)',
                'primarySiteBaseUrl' => Craft::$app->getSites()->getPrimarySite()->getBaseUrl() ?: '(empty)',
                'imgCount' => count($imgAnalysis),
                'imgAnalysis' => $imgAnalysis,
            ],
        ];
        @file_put_contents(self::LOG_PATH, json_encode($payload, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
        // #endregion
    }
}
