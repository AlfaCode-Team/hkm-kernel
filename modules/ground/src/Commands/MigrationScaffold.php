<?php

declare(strict_types=1);

namespace AlfacodeTeam\Ground\Commands;

use AlfacodeTeam\Ground\Database\DatabaseMatrix;

/**
 * The local files that make a plugin's migrations testable — written straight
 * into .gitignore, never into the package.
 *
 * ─── WHY THEY ARE NOT COMMITTED ─────────────────────────────────────────────
 *
 * `ground.databases.json` holds hostnames, usernames and PASSWORDS for servers
 * that exist on ONE machine. Committing it publishes credentials and, worse,
 * hands the next developer a config that points at databases they do not have —
 * so their run fails for a reason that has nothing to do with the plugin.
 *
 * `docker-compose.ground.yml` is a convenience for getting those servers, not
 * part of what the plugin IS. A plugin consumed from packagist should not carry
 * a compose file naming ports on someone's laptop.
 *
 * So both are generated on demand and added to `.gitignore` in the same breath.
 * A developer who wants them runs `--init`; nothing about the plugin changes.
 */
trait MigrationScaffold
{
    /** Every generated path, relative to the plugin. */
    private const IGNORED = [
        'ground.databases.json',
        'docker-compose.ground.yml',
    ];

    private function writeDatabaseScaffold(string $pluginDirectory): int
    {
        $dir = rtrim($pluginDirectory, '/');

        // .gitignore FIRST. If the run dies after writing the config but before
        // ignoring it, the next `git add -A` commits a file full of passwords.
        $this->ignoreLocalDbFiles($dir);

        $this->writeIfAbsent($dir . '/ground.databases.json', $this->databaseConfigTemplate());
        $this->writeIfAbsent($dir . '/docker-compose.ground.yml', $this->composeTemplate());

        $this->newLine();
        $this->info('Bring the servers up, then run `ground migrate`:');
        $this->muted('  docker compose -f docker-compose.ground.yml up -d');
        $this->newLine();
        $this->muted('No docker? Point ground.databases.json at servers you already have,');
        $this->muted('or export GROUND_DB_MYSQL=mysql://user:pass@host:3306 (env wins over the file).');

        return self::SUCCESS;
    }

    // ── Files ─────────────────────────────────────────────────────────────────

    private function databaseConfigTemplate(): string
    {
        // Ports deliberately NOT the defaults: a developer almost always has
        // something on 3306 already, and a scratch harness that fights the
        // local MySQL for a port is a bad first experience. They match the
        // compose file below.
        return json_encode([
            '//' => 'Local only — gitignored. Ground CREATES its own scratch database per run '
                . 'and drops it; the entries below name a SERVER, never a database to migrate. '
                . 'Set "enabled": false to skip one. Env vars (GROUND_DB_MYSQL, …) override this file.',
            'mysql' => [
                'host'     => '127.0.0.1',
                'port'     => 33061,
                'username' => 'root',
                'password' => 'ground',
            ],
            'pgsql' => [
                'host'     => '127.0.0.1',
                'port'     => 54321,
                'username' => 'postgres',
                'password' => 'ground',
            ],
            'sqlsrv' => [
                'enabled'  => false,
                'host'     => '127.0.0.1',
                'port'     => 14331,
                'username' => 'sa',
                'password' => 'Ground_Passw0rd',
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    }

    private function composeTemplate(): string
    {
        return <<<'YAML'
        # Local only — gitignored. Servers for `ground migrate`.
        #
        #   docker compose -f docker-compose.ground.yml up -d
        #   ground migrate
        #   docker compose -f docker-compose.ground.yml down -v
        #
        # Ports are offset from the defaults on purpose: a developer usually has
        # something on 3306/5432 already, and a scratch harness must not fight it.
        #
        # These databases hold nothing. Ground creates a uniquely named scratch
        # database per run and drops it afterwards, so `down -v` costs nothing.
        services:
          mysql:
            image: mysql:8.4
            environment:
              MYSQL_ROOT_PASSWORD: ground
            ports: ["33061:3306"]
            # Without a healthcheck the first `ground migrate` after `up -d`
            # races the server's own initialisation and reports a connection
            # refused that looks like a misconfiguration.
            healthcheck:
              test: ["CMD", "mysqladmin", "ping", "-h", "127.0.0.1", "-pground"]
              interval: 3s
              retries: 20

          postgres:
            image: postgres:17
            environment:
              POSTGRES_PASSWORD: ground
            ports: ["54321:5432"]
            healthcheck:
              test: ["CMD-SHELL", "pg_isready -U postgres"]
              interval: 3s
              retries: 20

          # Off by default: the image is ~1.5GB, needs pdo_sqlsrv in PHP (which
          # is not bundled), and refuses weak passwords. Enable it in
          # ground.databases.json when you actually target SQL Server.
          sqlserver:
            image: mcr.microsoft.com/mssql/server:2022-latest
            profiles: ["sqlsrv"]
            environment:
              ACCEPT_EULA: "Y"
              MSSQL_SA_PASSWORD: Ground_Passw0rd
            ports: ["14331:1433"]

        YAML;
    }

    // ── .gitignore ────────────────────────────────────────────────────────────

    private function ignoreLocalDbFiles(string $dir): void
    {
        $path     = $dir . '/.gitignore';
        $existing = is_file($path) ? (string) file_get_contents($path) : '';
        $missing  = [];

        foreach (self::IGNORED as $entry) {
            // Match whole lines: a substring test would consider
            // `ground.databases.json` already ignored because some other line
            // happens to contain `ground`.
            if (!$this->gitignoreHasLine($existing, $entry)) {
                $missing[] = $entry;
            }
        }

        if ($missing === []) {
            $this->muted('.gitignore already covers the local database files.');

            return;
        }

        $block = ($existing === '' || str_ends_with($existing, "\n") ? '' : "\n")
            . "\n# Local database harness (`ground migrate --init`) — machine-specific,\n"
            . "# holds credentials, and is regenerable. Never commit these.\n"
            . implode("\n", $missing) . "\n";

        file_put_contents($path, $existing . $block);
        $this->success('Added to .gitignore: ' . implode(', ', $missing));
    }

    private function gitignoreHasLine(string $haystack, string $needle): bool
    {
        foreach (explode("\n", $haystack) as $line) {
            if (trim($line) === $needle || trim($line) === '/' . $needle) {
                return true;
            }
        }

        return false;
    }

    private function writeIfAbsent(string $path, string $contents): void
    {
        if (is_file($path)) {
            $this->muted('kept (already exists): ' . basename($path));

            return;
        }

        file_put_contents($path, $contents);
        $this->success('wrote ' . basename($path));
    }

}
