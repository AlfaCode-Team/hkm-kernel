<?php

declare(strict_types=1);

namespace AlfacodeTeam\Ground\Database;

/**
 * One database a plugin's migrations should be proved against.
 *
 * A target is either REACHABLE — there is a server answering and ground may
 * create a scratch database on it — or it carries the reason it is not. The
 * distinction is the whole point of the type: a migration suite that quietly
 * runs on SQLite and reports "passed" tells you nothing about the MySQL you are
 * actually deploying to, and the failure mode of pretending otherwise is a
 * production migration that has never been executed anywhere.
 */
final readonly class DatabaseTarget
{
    private function __construct(
        /** Driver name as LetMigrate's DriverRegistry knows it. */
        public string $driver,
        /** @var array<string, mixed> connection config, minus the database name */
        public array $server,
        public bool $reachable,
        /** Why it is not reachable. Empty when it is. */
        public string $skipReason = '',
    ) {}

    /** @param array<string, mixed> $server */
    public static function reachable(string $driver, array $server): self
    {
        return new self($driver, $server, true);
    }

    /** @param array<string, mixed> $server */
    public static function unreachable(string $driver, array $server, string $why): self
    {
        return new self($driver, $server, false, $why);
    }

    /** A one-line description of where this points, with no password in it. */
    public function describe(): string
    {
        if ($this->driver === 'sqlite') {
            return 'sqlite (file)';
        }

        return sprintf(
            '%s://%s@%s:%s',
            $this->driver,
            (string) ($this->server['username'] ?? ''),
            (string) ($this->server['host'] ?? ''),
            (string) ($this->server['port'] ?? ''),
        );
    }
}
