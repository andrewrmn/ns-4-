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
    '*' => [
        'defaultWeekStartDay' => 0,
        'omitScriptNameInUrls' => true,
        'cpTrigger' => 'admin',
        'securityKey' => getenv('CRAFT_SECURITY_KEY'),
        'backupOnUpdate' => false,
        'extraAllowedFileExtensions' => 'csv',
        'tokenParam' => 'craftToken',
        'rememberedUserSessionDuration' => 5184000,
        'aliases' => [
            '@baseUrl' => getenv('DEFAULT_SITE_URL'),
        ],
        'rememberedUserSessionDuration' => 'P1Y',
        'userSessionDuration' => 'P1Y',
        'extraAllowedFileExtensions' => ['htm', 'html'],
        'enableCsrfProtection' => false,
        'enableCsrfProtection' => (!isset($_SERVER['REQUEST_URI']) || $_SERVER['REQUEST_URI'] != '/patients/autoship/create-cart'),
    ],

    'dev' => [
        'enableCsrfProtection' => false,
		'allowAdminChanges' => true,
    ],

    'staging' => [
        'allowAdminChanges' => true,
    ],

    'production' => [
        'allowAdminChanges' => true,
    ],
];
