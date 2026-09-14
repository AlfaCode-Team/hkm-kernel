<?php

declare(strict_types=1);

namespace Tests\Feature\Support\Fakes;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;

/**
 * A DatabasePort that answers from a scripted result set and records every
 * statement it was given.
 *
 * WHY NOT SQLITE
 * --------------
 * An in-memory SQLite would be more faithful, but it would also make every
 * feature test carry a schema. The defects a feature test exists to catch here
 * are wiring defects — was the repository even reached, with which tenant, in a
 * transaction or not — and those are visible in the statement log. A test that
 * genuinely needs SQL semantics should use a real driver.
 *
 * Transaction state is REAL, because "the integration event was dispatched
 * inside the try block" is exactly the kind of thing this suite must be able to
 * fail on.
 */
final class ArrayDatabase implements DatabasePort
{
    /** @var list<array{sql: string, params: array<string, mixed>}> */
    public array $statements = [];

    /** @var list<string> begin/commit/rollback, in order */
    public array $transactions = [];

    /** @var array<string, list<array<string, mixed>>> SQL fragment => rows to return */
    private array $answers = [];

    private int $depth = 0;
    private int $autoIncrement = 0;

    /** Throw on the next write, to exercise a rollback path. */
    public ?\Throwable $failNextWrite = null;

    /**
     * Answer any query whose SQL CONTAINS $fragment with $rows.
     *
     * Matching on a fragment rather than the whole statement keeps a test
     * readable ('SELECT * FROM invoices' rather than the exact whitespace) while
     * still failing loudly when the query changes shape.
     *
     * @param list<array<string, mixed>> $rows
     */
    public function answer(string $fragment, array $rows): self
    {
        $this->answers[$fragment] = $rows;

        return $this;
    }

    public function query(string $sql, array $params = []): array
    {
        $this->statements[] = ['sql' => $sql, 'params' => $params];

        foreach ($this->answers as $fragment => $rows) {
            if (str_contains($sql, $fragment)) {
                return $rows;
            }
        }

        return [];
    }

    public function queryOne(string $sql, array $params = []): ?array
    {
        return $this->query($sql, $params)[0] ?? null;
    }

    public function execute(string $sql, array $params = []): int
    {
        if ($this->failNextWrite !== null) {
            $failure = $this->failNextWrite;
            $this->failNextWrite = null;

            throw $failure;
        }

        $this->statements[] = ['sql' => $sql, 'params' => $params];
        $this->autoIncrement++;

        return 1;
    }

    public function upsert(string $table, array $values, array $conflictColumns, ?array $updateColumns = null): int
    {
        $this->statements[] = [
            'sql'    => "UPSERT {$table}",
            'params' => ['values' => $values, 'conflict' => $conflictColumns, 'update' => $updateColumns],
        ];

        return 1;
    }

    public function lastInsertId(?string $sequence = null): string
    {
        return (string) $this->autoIncrement;
    }

    public function beginTransaction(): void
    {
        $this->transactions[] = 'begin';
        $this->depth++;
    }

    public function commit(): void
    {
        $this->transactions[] = 'commit';
        $this->depth = max(0, $this->depth - 1);
    }

    public function rollback(): void
    {
        $this->transactions[] = 'rollback';
        $this->depth = max(0, $this->depth - 1);
    }

    public function inTransaction(): bool
    {
        return $this->depth > 0;
    }

    /** @return list<string> just the SQL, for readable assertions */
    public function sql(): array
    {
        return array_column($this->statements, 'sql');
    }

    /** Whether any statement contained this fragment. */
    public function ran(string $fragment): bool
    {
        foreach ($this->sql() as $sql) {
            if (str_contains($sql, $fragment)) {
                return true;
            }
        }

        return false;
    }
}
