<?php

declare(strict_types=1);

/**
 * LetMigrate config — LOCAL environment (APP_ENV=local).
 *
 * Overrides config/let-migrate.php when APP_ENV=local. Keeps the zero-setup
 * SQLite database for development; point `connections.default` at a real driver
 * (mysql/pgsql) if your local stack uses one. See the base config for every key.
 */

$root = dirname(__DIR__, 2);

return [
    'default' => 'default',
    'connections' => [
        'default' => [
            'driver' => env('DB_DRIVER', 'sqlite'),
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