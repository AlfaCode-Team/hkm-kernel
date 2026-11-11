<?php

declare(strict_types=1);

namespace Tests\Unit\Kernel\Ports\Document;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Document\DocumentFilter;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Document\DocumentQuery;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DocumentStorePort;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The convergence mandate, locked to numbers measured against a real mongod.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * WHAT THIS TEST DOES AND DOES NOT PROVE
 * ────────────────────────────────────────────────────────────────────────────
 * It does NOT prove how MongoDB behaves. That was established by running the
 * queries below against MongoDB 9.0.4 over these exact five documents:
 *
 *   sort by `priority`                         → 5 documents (the one lacking
 *                                                 `priority` sorted FIRST)
 *   sort by `priority` + {$exists: true}       → 4 documents
 *   {status: {$ne: 'paid'}}                    → 4 documents (including one
 *                                                 with no `status` and one with
 *                                                 `status: null`)
 *   the same + {$exists: true} + {$ne: null}   → 2 documents
 *
 * What this test proves is the half that lives in the kernel: that
 * {@see DocumentQuery::requiredFields()} and {@see DocumentQuery::nonNullFields()}
 * report exactly the constraints that turn the first number into the second —
 * which is what an adapter relies on to make one query mean one thing on both
 * stores.
 *
 * The evaluator below is a MODEL of the measured MongoDB semantics, not a
 * MongoDB client: a missing field sorts first and a `!=` matches a missing or
 * null field, because that is what the real server did. If someone later
 * changes `requiredFields()` to return less, the naive and converged counts
 * stop differing and these tests fail — which is the regression worth catching,
 * since the symptom in production is a list page quietly missing rows.
 */
#[CoversClass(DocumentQuery::class)]
final class DocumentConvergenceTest extends TestCase
{
    /**
     * The fixture used against the real mongod, verbatim.
     *
     * @return array<string, array<string, mixed>>
     */
    private function documents(): array
    {
        return [
            'has-open'    => ['status' => 'open', 'priority' => 2],
            'has-paid'    => ['status' => 'paid', 'priority' => 1],
            'no-status'   => ['priority' => 3],                    // status ABSENT
            'null-status' => ['status' => null, 'priority' => 4],   // status NULL
            'no-priority' => ['status' => 'open'],                  // priority ABSENT
        ];
    }

    public function test_ordering_by_an_optional_field_drops_a_document_once_converged(): void
    {
        $query = DocumentQuery::for('conv')->orderBy('priority');

        self::assertCount(
            5,
            $this->runQuery($query, converge: false),
            'MongoDB keeps a document that lacks the sort field — measured on 9.0.4',
        );

        self::assertCount(
            4,
            $this->runQuery($query, converge: true),
            'Firestore excludes it, because an orderBy also filters for existence; applying '
            . 'requiredFields() is what makes the two stores agree',
        );
    }

    public function test_the_dropped_document_is_exactly_the_one_missing_the_field(): void
    {
        $query = DocumentQuery::for('conv')->orderBy('priority');

        $naive     = $this->runQuery($query, converge: false);
        $converged = $this->runQuery($query, converge: true);

        self::assertSame(
            ['no-priority'],
            array_values(array_diff($naive, $converged)),
            'the difference must be the document with no priority, not an arbitrary one',
        );

        self::assertSame(
            'no-priority',
            $naive[0],
            'and on MongoDB it arrives FIRST, so a "top N by priority" query returns it as the '
            . 'highest-priority row — which is the shape of the bug, not merely an extra row',
        );
    }

    public function test_not_equal_matches_missing_and_null_until_converged(): void
    {
        $query = DocumentQuery::for('conv')->where(DocumentFilter::ne('status', 'paid'));

        self::assertSame(
            ['has-open', 'no-status', 'null-status', 'no-priority'],
            $this->runQuery($query, converge: false),
            'MongoDB matches the absent and the null status — measured on 9.0.4',
        );

        self::assertSame(
            ['has-open', 'no-priority'],
            $this->runQuery($query, converge: true),
            'Firestore matches neither, so "everything not yet paid" means two different sets of '
            . 'records depending on the store',
        );
    }

    public function test_the_mandate_reports_both_constraints_for_a_not_equal_filter(): void
    {
        $query = DocumentQuery::for('conv')->where(DocumentFilter::ne('status', 'paid'));

        self::assertSame(['status'], $query->requiredFields());
        self::assertSame(
            ['status'],
            $query->nonNullFields(),
            'existence alone is not enough for != — an explicit null must go too, and $exists '
            . 'is true for a field holding null',
        );
    }

    public function test_an_equality_query_needs_no_convergence_at_all(): void
    {
        // The point of recording this: the mandate must not quietly narrow
        // queries that were already portable, or every filter would start
        // dropping documents whose fields are simply absent elsewhere.
        $query = DocumentQuery::for('conv')->where(DocumentFilter::eq('status', 'open'));

        self::assertSame(
            $this->runQuery($query, converge: false),
            $this->runQuery($query, converge: true),
        );
    }

    public function test_the_port_interface_declares_the_methods_an_adapter_must_implement(): void
    {
        // A cheap guard with a real purpose: these names appear in the
        // convergence mandate in the port's docblock, and a rename that left
        // the prose behind would make the instructions to adapter authors point
        // at methods that no longer exist.
        foreach (['get', 'find', 'count', 'insert', 'create', 'put', 'patch',
                  'increment', 'delete', 'ensureIndex', 'dropIndex', 'listIndexes',
                  'supports', 'describe'] as $method) {
            self::assertTrue(
                method_exists(DocumentStorePort::class, $method),
                "DocumentStorePort::{$method}() is referenced by the port's own documentation",
            );
        }
    }

    /**
     * Evaluate a query over the fixture, optionally applying the mandate.
     *
     * Named `runQuery`, not `run`: {@see TestCase::run()} is final, and a
     * helper that shadows it is a fatal error at class-load time which aborts
     * the whole suite rather than failing one test.
     *
     * @return list<string> matching ids, in order
     */
    private function runQuery(DocumentQuery $query, bool $converge): array
    {
        $required = $converge ? $query->requiredFields() : [];
        $nonNull  = $converge ? $query->nonNullFields() : [];

        $matched = [];

        foreach ($this->documents() as $id => $data) {
            foreach ($query->filters as $filter) {
                if (!$this->satisfies($data, $filter)) {
                    continue 2;
                }
            }

            // The two constraints an adapter adds. `$exists` is true for a
            // field holding null, which is why non-nullness is separate.
            foreach ($required as $field) {
                if (!\array_key_exists($field, $data)) {
                    continue 2;
                }
            }

            foreach ($nonNull as $field) {
                if (($data[$field] ?? null) === null) {
                    continue 2;
                }
            }

            $matched[$id] = $data;
        }

        foreach (array_reverse(array_keys($query->orderBy)) as $field) {
            // Stable sort on a missing field as null, which orders it first —
            // the measured MongoDB behaviour being modelled.
            uasort(
                $matched,
                static fn (array $a, array $b): int => ($a[$field] ?? null) <=> ($b[$field] ?? null),
            );
        }

        return array_keys($matched);
    }

    /** @param array<string, mixed> $data */
    private function satisfies(array $data, DocumentFilter $filter): bool
    {
        $value = $data[$filter->field] ?? null;

        return match ($filter->operator->value) {
            'eq'  => $value === $filter->value,
            // Modelled on MongoDB: a missing field compares as null, and null
            // is not equal to the sought value, so the predicate is true.
            'ne'  => $value !== $filter->value,
            'lt'  => $value !== null && $value < $filter->value,
            'lte' => $value !== null && $value <= $filter->value,
            'gt'  => $value !== null && $value > $filter->value,
            'gte' => $value !== null && $value >= $filter->value,
            default => self::fail(
                'this model only covers the operators the convergence tests use; '
                . $filter->operator->value . ' was not expected',
            ),
        };
    }
}
