<?php
/**
 * Database Configuration
 *
 * All of your system's database connection settings go in here. You can see a
 * list of the available settings in vendor/craftcms/cms/src/config/DbConfig.php.
 *
 * @see craft\config\DbConfig
 */


use craft\helpers\App;

return [
    'dsn' => App::env('DB_DSN'),  // Must be set in .env - no fallback for security
    'user' => App::env('DB_USER'),  // Must be set in .env - no fallback for security
    'password' => App::env('DB_PASSWORD'),  // Must be set in .env - no fallback for security
    'schema' => App::env('DB_SCHEMA') ?: '',
    'tablePrefix' => App::env('DB_TABLE_PREFIX') ?: 'craft_',
    'charset' => 'utf8mb4',
    'attributes' => [
        PDO::ATTR_PERSISTENT => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET sql_mode='STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO'",
    ],
];
