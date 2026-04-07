<?php
/**
 * General Configuration
 *
 * All of your system's general configuration settings go in here. You can see a
 * list of the available settings in vendor/craftcms/cms/src/config/GeneralConfig.php.
 *
 * @see \craft\config\GeneralConfig
 */

return [
    // Global settings
    '*' => [
        'defaultWeekStartDay' => 0,
        'omitScriptNameInUrls' => true,
        'cpTrigger' => 'admin',
        'securityKey' => getenv('SECURITY_KEY'),
        'backupOnUpdate' => false,
        'extraAllowedFileExtensions' => 'csv',
        'tokenParam' => 'craftToken',
        'rememberedUserSessionDuration' => 5184000,
        'aliases' => [
            '@baseUrl' => getenv('DEFAULT_SITE_URL'),
        ],
        'rememberedUserSessionDuration' => 'P1Y',
        'userSessionDuration' => 'P1Y',
        'timezone' => 'America/Chicago',
        'isSystemLive' => true,
        'extraAllowedFileExtensions' => ['htm', 'html'],
        'enableCsrfProtection' => false,
        'enableCsrfProtection' => (!isset($_SERVER['REQUEST_URI']) || $_SERVER['REQUEST_URI'] != '/patients/autoship/create-cart'),
    ],

    // Dev environment settings
    'dev' => [
        // Dev Mode (see https://craftcms.com/guides/what-dev-mode-does)
        'enableCsrfProtection' => false,
        //'isSystemLive' => true
        //'allowAdminChanges' => true,
    ],

    // Staging environment settings
    'staging' => [
        // Set this to `false` to prevent administrative changes from being made on staging
        'allowAdminChanges' => false,
    ],

    // Production environment settings
    'production' => [
        // Set this to `false` to prevent administrative changes from being made on production
        'allowAdminChanges' => false,
    ],
];
