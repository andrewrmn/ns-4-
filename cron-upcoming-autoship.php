<?php
/**
 * Cloudways PHP cron entry: upcoming autoship reminder email.
 *
 * In Cloudways → Cron: Type PHP, command field = cron-upcoming-autoship.php
 * (path prefix must be the Craft project root that contains the `craft` file).
 *
 * @see modules/autoshipschedule/console/controllers/AutoShipController::actionUpcomingAutoshipEmail()
 */
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

$root = __DIR__;
$craft = $root . DIRECTORY_SEPARATOR . 'craft';
if (!is_file($craft)) {
    fwrite(STDERR, "Craft console not found: {$craft}\n");
    exit(1);
}

$phpBinary = (defined('PHP_BINARY') && PHP_BINARY !== '') ? PHP_BINARY : 'php';
$cmd = escapeshellarg($phpBinary) . ' ' . escapeshellarg($craft) . ' autoship-schedule/auto-ship/upcoming-autoship-email';

chdir($root);
passthru($cmd, $exitCode);
exit((int) $exitCode);
