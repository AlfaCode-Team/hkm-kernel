<?php

declare(strict_types=1);

/**
 * LetMigrate config — STAGING environment (APP_ENV=staging).
 *
 * Overrides config/let-migrate.php when APP_ENV=staging. Mirror production's
 * connection here against your staging database so migrations are validated in a
 * production-like setting before release. See the base config for every key.
 */

$root = dirname(__DIR__, 2);

return [
    'default' => 'default',
    'connections' => [
        'default' => [
            'driver' => env('DB_DRIVER', 'mysql'),
            'database' => env('DB_NAME', $root . '/database/app.sqlite'),
            'host' => env('DB_HOST', 'localhost'),
            'port' => env('DB_PORT', 0),
            'username' => env('DB_USERNAME', ''),
            'password' => env('DB_PASSWORD', ''),
        ],
    ],
    'paths' => [
        $root . '/database/migrations',
    ],
    'seeders_path' => $root . '/database/seeders',
    'factories_path' => $root . '/database/factories',
    'tracking_table' => 'let_migrations',
    'pretend' => false,
    'transactional' => false,
];