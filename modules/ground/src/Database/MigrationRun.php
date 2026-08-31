<?php

declare(strict_types=1);

namespace AlfacodeTeam\Ground\Database;

/** What happened when one plugin's migrations met one real database. */
final readonly class MigrationRun
{
    private function __construct(
        public DatabaseTarget $target,
        public bool $ran,
        public int $applied,
        /** @var list<string> tables that existed after up() */
        public array $tables,
        public bool $rolledBackClean,
        public string $error = '',
        /** How many seeders ran against the built schema. */
        public int $seeded = 0,
        /** 'central' or 'tenant' — which database this run built. */
        public string $layer = 'central',
    ) {}

    /** @param list<string> $tables */
    public static function passed(
        DatabaseTarget $target,
        int $applied,
        array $tables,
        bool $rolledBackClean,
        int $seeded = 0,
        string $layer = 'central',
    ): self {
        return new self($target, true, $applied, $tables, $rolledBackClean, '', $seeded, $layer);
    }

    public static function failed(DatabaseTarget $target, string $error, string $layer = 'central'): self
    {
        return new self($target, true, 0, [], false, $error, 0, $layer);
    }

    public static function skipped(DatabaseTarget $target, string $layer = 'central'): self
    {
        return new self($target, false, 0, [], false, $target->skipReason, 0, $layer);
    }

    public function ok(): bool
    {
        return $this->ran && $this->error === '' && $this->rolledBackClean;
    }
}
