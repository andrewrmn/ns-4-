<?php

namespace modules\neuroselect;

use Craft;

/**
 * Debug NDJSON for PDF pipeline (session 7b4473). Not for production long-term.
 */
final class PdfDebugSessionLog
{
    private const SESSION_ID = '7b4473';

    private const PRIMARY_LOG = '/Users/andrewross/Sites/neuroscience-3/.cursor/debug-7b4473.log';

    public static function write(string $hypothesisId, string $location, string $message, array $data = []): void
    {
        $payload = [
            'sessionId' => self::SESSION_ID,
            'timestamp' => (int) round(microtime(true) * 1000),
            'hypothesisId' => $hypothesisId,
            'location' => $location,
            'message' => $message,
            'data' => $data,
        ];
        $line = json_encode($payload, JSON_UNESCAPED_SLASHES) . "\n";
        $written = @file_put_contents(self::PRIMARY_LOG, $line, FILE_APPEND | LOCK_EX);
        if ($written === false && class_exists(Craft::class)) {
            $fallback = Craft::getAlias('@storage') . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'debug-7b4473.ndjson';
            @file_put_contents($fallback, $line, FILE_APPEND | LOCK_EX);
        }
    }
}
