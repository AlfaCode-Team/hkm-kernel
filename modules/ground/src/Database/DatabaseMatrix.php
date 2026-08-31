<?php

declare(strict_types=1);

namespace AlfacodeTeam\Ground\Database;

/**
 * Which real databases this machine can prove a plugin's migrations against.
 *
 * ─── WHERE THE CONNECTIONS COME FROM ────────────────────────────────────────
 *
 * `ground.databases.json`, beside the plugin's module.json. It holds hostnames,
 * usernames and PASSWORDS, so it is written into the plugin's .gitignore the
 * moment it is created and is never part of the package: the servers a
 * developer has are a property of that developer's machine, not of the plugin.
 *
 * Environment variables override the file, one per driver, so CI can supply
 * service containers without writing a file at all:
 *
 *     GROUND_DB_MYSQL=mysql://root:secret@127.0.0.1:3306
 *     GROUND_DB_PGSQL=pgsql://postgres:secret@127.0.0.1:5432
 *     GROUND_DB_SQLSRV=sqlsrv://sa:Secret_123@127.0.0.1:1433
 *
 * SQLite needs no configuration and is always present: it is a file, and the
 * PDO driver ships with PHP.
 *
 * ─── REACHABILITY IS PROBED, NOT ASSUMED ────────────────────────────────────
 *
 * Every target is connected to before it is offered, with a short timeout. A
 * driver that is configured but not answering is reported as SKIPPED with the
 * reason, never silently dropped and never counted as a pass — "3 of 4
 * databases were not tested" is the single most important line such a run can
 * print, and it is worthless if a missing server looks like a green tick.
 */
final class DatabaseMatrix
{
    /** Every driver LetMigrate supports, in the order a report should list them. */
    public const DRIVERS = ['sqlite', 'mysql', 'pgsql', 'sqlsrv'];

    private const ENV = [
        'mysql'  => 'GROUND_DB_MYSQL',
        'pgsql'  => 'GROUND_DB_PGSQL',
        'sqlsrv' => 'GROUND_DB_SQLSRV',
    ];

    /** The PDO driver each needs, for a precise "why not" message. */
    private const PDO_DRIVER = [
        'sqlite' => 'sqlite',
        'mysql'  => 'mysql',
        'pgsql'  => 'pgsql',
        'sqlsrv' => 'sqlsrv',
    ];

    private function __construct(
        /** @var list<DatabaseTarget> */
        public readonly array $targets,
    ) {}

    /** The config file a plugin may carry, gitignored. */
    public static function configPath(string $pluginDirectory): string
    {
        return rtrim($pluginDirectory, '/') . '/ground.databases.json';
    }

    /** Probe every driver and report what this machine can actually do. */
    public static function discover(string $pluginDirectory): self
    {
        $configured = self::readConfig($pluginDirectory);
        $targets    = [];

        foreach (self::DRIVERS as $driver) {
            $targets[] = self::probe($driver, $configured[$driver] ?? null);
        }

        return new self($targets);
    }

    /** @return list<DatabaseTarget> */
    public function usable(): array
    {
        return array_values(array_filter($this->targets, static fn(DatabaseTarget $t): bool => $t->reachable));
    }

    /** @return list<DatabaseTarget> */
    public function skipped(): array
    {
        return array_values(array_filter($this->targets, static fn(DatabaseTarget $t): bool => !$t->reachable));
    }

    // ── Probing ───────────────────────────────────────────────────────────────

    /** @param array<string, mixed>|null $config */
    private static function probe(string $driver, ?array $config): DatabaseTarget
    {
        $pdoDriver = self::PDO_DRIVER[$driver];

        if (!\in_array($pdoDriver, \PDO::getAvailableDrivers(), true)) {
            // sqlsrv in particular: `dblib` can also reach SQL Server, but
            // LetMigrate's driver asks PDO for `sqlsrv` by name, so having
            // dblib is not the same as being able to run this.
            return DatabaseTarget::unreachable($driver, $config ?? [], "PHP has no pdo_{$pdoDriver} extension");
        }

        // SQLite is a file: nothing to reach, nothing to configure.
        if ($driver === 'sqlite') {
            return DatabaseTarget::reachable('sqlite', []);
        }

        if ($config === null) {
            $env = self::ENV[$driver];

            return DatabaseTarget::unreachable(
                $driver,
                [],
                "not configured — add it to ground.databases.json or set {$env}",
            );
        }

        try {
            // Connect to the SERVER, not to a database: ground creates its own
            // scratch database per run, so the one named in config need not
            // exist and must never be written to.
            new \PDO(
                self::serverDsn($driver, $config),
                (string) ($config['username'] ?? ''),
                (string) ($config['password'] ?? ''),
                [\PDO::ATTR_TIMEOUT => 3, \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
            );
        } catch (\Throwable $e) {
            return DatabaseTarget::unreachable($driver, $config, self::firstLine($e->getMessage()));
        }

        return DatabaseTarget::reachable($driver, $config);
    }

    /**
     * A DSN pointing at the SERVER's administrative database.
     *
     * `CREATE DATABASE` has to be issued from a connection that is not inside
     * the database being created, so each engine has a conventional entry
     * point: mysql's `mysql`, postgres's `postgres`, SQL Server's `master`.
     *
     * @param array<string, mixed> $config
     */
    public static function serverDsn(string $driver, array $config): string
    {
        $host = (string) ($config['host'] ?? '127.0.0.1');
        $port = (int) ($config['port'] ?? self::defaultPort($driver));

        return match ($driver) {
            'mysql'  => "mysql:host={$host};port={$port};dbname=mysql",
            'pgsql'  => "pgsql:host={$host};port={$port};dbname=postgres",
            'sqlsrv' => "sqlsrv:Server={$host},{$port};Database=master",
            default  => throw new \InvalidArgumentException("No server DSN for [{$driver}]."),
        };
    }

    public static function defaultPort(string $driver): int
    {
        return match ($driver) {
            'mysql'  => 3306,
            'pgsql'  => 5432,
            'sqlsrv' => 1433,
            default  => 0,
        };
    }

    // ── Configuration ─────────────────────────────────────────────────────────

    /**
     * Connections by driver: the file first, environment on top.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function readConfig(string $pluginDirectory): array
    {
        $out  = [];
        $path = self::configPath($pluginDirectory);

        if (is_file($path)) {
            $decoded = json_decode((string) file_get_contents($path), true);

            foreach (\is_array($decoded) ? $decoded : [] as $driver => $entry) {
                if (\is_array($entry) && ($entry['enabled'] ?? true) !== false) {
                    $out[(string) $driver] = $entry;
                }
            }
        }

        // Env wins: CI supplies service containers this way and must not need a
        // file, nor be overridden by one a developer happened to commit.
        foreach (self::ENV as $driver => $var) {
            $url = getenv($var);

            if (\is_string($url) && $url !== '') {
                $parsed = self::parseUrl($url);

                if ($parsed !== null) {
                    $out[$driver] = $parsed;
                }
            }
        }

        return $out;
    }

    /**
     * `mysql://user:pass@host:3306` → connection config.
     *
     * @return array<string, mixed>|null
     */
    private static function parseUrl(string $url): ?array
    {
        $parts = parse_url($url);

        if ($parts === false || !isset($parts['host'])) {
            return null;
        }

        return [
            'host'     => $parts['host'],
            'port'     => $parts['port'] ?? null,
            'username' => isset($parts['user']) ? rawurldecode($parts['user']) : '',
            'password' => isset($parts['pass']) ? rawurldecode($parts['pass']) : '',
        ];
    }

    /** PDO's connection errors are multi-line; the first line is the useful one. */
    private static function firstLine(string $message): string
    {
        $line = strtok($message, "\n");

        return $line === false ? $message : trim($line);
    }
}
