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
    ) {}

    /** @param list<string> $tables */
    public static function passed(DatabaseTarget $target, int $applied, array $tables, bool $rolledBackClean): self
    {
        return new self($target, true, $applied, $tables, $rolledBackClean);
    }

    public static function failed(DatabaseTarget $target, string $error): self
    {
        return new self($target, true, 0, [], false, $error);
    }

    public static function skipped(DatabaseTarget $target): self
    {
        return new self($target, false, 0, [], false, $target->skipReason);
    }

    public function ok(): bool
    {
        return $this->ran && $this->error === '' && $this->rolledBackClean;
    }
}
