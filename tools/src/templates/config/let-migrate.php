<?php

declare(strict_types=1);

/**
 * =============================================================================
 *  LetMigrate CONFIGURATION  (config/let-migrate.php)
 * =============================================================================
 *
 * Configures the LetMigrate database-migration engine used by the migration CLI
 * (make:migration, migrate, migrate:rollback, db:seed, ...). This is the BASE
 * config; config/environments/<APP_ENV>.php overrides it per environment (e.g.
 * a real MySQL/Postgres connection in production).
 *
 * Keys:
 *   default         which entry in `connections` to use.
 *   connections     named DB connections. driver: sqlite | mysql | pgsql | sqlsrv.
 *                   The fluent Blueprint API compiles one migration to the right
 *                   dialect per driver — write once, run on any database.
 *   paths           directories scanned for migration classes.
 *   seeders_path    directory holding seeder classes (db:seed).
 *   factories_path  directory holding model/data factories.
 *   tracking_table  table that records which migrations have run (batched).
 *   pretend         true = print the SQL instead of executing it (CI previews
 *                   only — never in production).
 *   transactional   wrap each migration run in a transaction (auto-rollback on
 *                   failure). Off for SQLite-in-dev where DDL is non-transactional.
 *
 * `$root` is the project root (this file lives at <root>/config/let-migrate.php),
 * so the SQLite database and the migration/seeder/factory folders resolve under
 * the project by default.
 * =============================================================================
 */

$root = dirname(__DIR__, 1);

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
    'transactional' => true,
];