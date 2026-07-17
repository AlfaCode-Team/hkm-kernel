<?php

declare(strict_types=1);

/**
 * LetMigrate config — TESTING environment (APP_ENV=testing).
 *
 * Overrides config/let-migrate.php when APP_ENV=testing. Use a disposable
 * database for the test suite (an in-memory SQLite via DB_DSN=sqlite::memory: is
 * ideal — fast and isolated per run). See the base config for every key.
 */

$root = dirname(__DIR__, 2);

return [
    'default' => 'default',
    'connections' => [
        'default' => [
            'driver' => env('DB_DRIVER', 'sqlite'),
            'database' => env('DB_NAME', ':memory:'),
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