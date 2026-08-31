<?php

declare(strict_types=1);

namespace AlfacodeTeam\Ground\Fakes;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;

/**
 * A DatabasePort that runs no SQL.
 *
 * It is NOT a database. It records every statement and returns results the test
 * queued in advance. That limitation is the point: a repository test that needs
 * a real engine to pass is testing the engine, and the thing worth asserting —
 * that the service committed, that the rollback discarded the events, that the
 * query was tenant-scoped — is visible here without one.
 *
 * Queue results with `willReturn()` (matched in FIFO order) or `onQuery()`
 * (matched by substring, so a test names the statement it means). Anything
 * unmatched returns an empty result rather than throwing: a repository usually
 * runs several statements and a test should only have to describe the one it
 * cares about.
 */
final class FakeDatabase implements DatabasePort
{
    /** @var list<array{sql: string, params: array<string, mixed>}> */
    public array $queries = [];

    /** @var list<list<array<string, mixed>>> */
    private array $queued = [];

    /** @var list<array{needle: string, rows: list<array<string, mixed>>}> */
    private array $matchers = [];

    private int $depth = 0;
    public int $commits = 0;
    public int $rollbacks = 0;
    public int $affectedRows = 1;
    private string $lastInsertId = '1';

    /** Statement that should throw, by substring. @var array<string, \Throwable> */
    private array $failures = [];

    // ── Arranging ─────────────────────────────────────────────────────────────

    /** Queue one result set, consumed by the next query()/queryOne() that has no matcher. */
    public function willReturn(array ...$rows): self
    {
        $this->queued[] = array_values($rows);
        return $this;
    }

    /** Return $rows for any statement CONTAINING $needle (case-insensitive). */
    public function onQuery(string $needle, array ...$rows): self
    {
        $this->matchers[] = ['needle' => strtolower($needle), 'rows' => array_values($rows)];
        return $this;
    }

    /** Make any statement containing $needle throw — for testing the rollback path. */
    public function failOn(string $needle, ?\Throwable $error = null): self
    {
        $this->failures[strtolower($needle)] = $error ?? new \PDOException("FakeDatabase: forced failure on [{$needle}].");
        return $this;
    }

    public function willInsertId(string $id): self
    {
        $this->lastInsertId = $id;
        return $this;
    }

    // ── DatabasePort ──────────────────────────────────────────────────────────

    public function query(string $sql, array $params = []): array
    {
        return $this->record($sql, $params);
    }

    public function queryOne(string $sql, array $params = []): ?array
    {
        return $this->record($sql, $params)[0] ?? null;
    }

    public function execute(string $sql, array $params = []): int
    {
        $this->record($sql, $params);
        return $this->affectedRows;
    }

    public function upsert(string $table, array $values, array $conflictColumns, ?array $updateColumns = null): int
    {
        $this->queries[] = [
            'sql'    => "UPSERT INTO {$table}",
            'params' => ['values' => $values, 'conflict' => $conflictColumns, 'update' => $updateColumns],
        ];

        return $this->affectedRows;
    }

    public function lastInsertId(?string $sequence = null): string
    {
        return $this->lastInsertId;
    }

    public function beginTransaction(): void
    {
        $this->depth++;
    }

    public function commit(): void
    {
        // Mirrors a real driver: committing with nothing open is a bug in the
        // caller, and swallowing it here would hide exactly the ordering
        // mistake a service test exists to catch.
        if ($this->depth === 0) {
            throw new \LogicException('FakeDatabase: commit() with no open transaction.');
        }
        $this->depth--;
        $this->commits++;
    }

    public function rollback(): void
    {
        if ($this->depth === 0) {
            throw new \LogicException('FakeDatabase: rollback() with no open transaction.');
        }
        $this->depth--;
        $this->rollbacks++;
    }

    public function inTransaction(): bool
    {
        return $this->depth > 0;
    }

    // ── Asserting ─────────────────────────────────────────────────────────────

    public function committed(): bool
    {
        return $this->commits > 0;
    }

    public function rolledBack(): bool
    {
        return $this->rollbacks > 0;
    }

    /** True when any recorded statement contains $needle (case-insensitive). */
    public function ran(string $needle): bool
    {
        $needle = strtolower($needle);
        foreach ($this->queries as $q) {
            if (str_contains(strtolower($q['sql']), $needle)) {
                return true;
            }
        }
        return false;
    }

    /** Every bound parameter set for statements containing $needle. */
    public function paramsFor(string $needle): array
    {
        $needle = strtolower($needle);
        $out    = [];
        foreach ($this->queries as $q) {
            if (str_contains(strtolower($q['sql']), $needle)) {
                $out[] = $q['params'];
            }
        }
        return $out;
    }

    public function reset(): void
    {
        $this->queries   = [];
        $this->queued    = [];
        $this->matchers  = [];
        $this->failures  = [];
        $this->depth     = 0;
        $this->commits   = 0;
        $this->rollbacks = 0;
    }

    private function record(string $sql, array $params): array
    {
        $this->queries[] = ['sql' => $sql, 'params' => $params];
        $lower = strtolower($sql);

        foreach ($this->failures as $needle => $error) {
            if (str_contains($lower, $needle)) {
                throw $error;
            }
        }

        foreach ($this->matchers as $matcher) {
            if (str_contains($lower, $matcher['needle'])) {
                return $matcher['rows'];
            }
        }

        return array_shift($this->queued) ?? [];
    }
}
