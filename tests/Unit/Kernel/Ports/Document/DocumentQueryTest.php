<?php

declare(strict_types=1);

namespace Tests\Unit\Kernel\Ports\Document;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Document\DocumentFilter;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Document\DocumentQuery;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Document\SortDirection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The query refuses what only ONE document store can run.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * WHY THESE TESTS ARE ABOUT FAILURES
 * ────────────────────────────────────────────────────────────────────────────
 * Every refusal below describes a query MongoDB runs happily and Firestore
 * rejects. Without the refusal the sequence is always the same: it is written
 * against Mongo, it passes, it ships, and it throws the first time the other
 * adapter is configured — which is to say in whichever environment is not the
 * developer's.
 *
 * So the tests assert that the failure happens HERE, at construction, with a
 * message naming the fix. A test that a legal query is accepted proves much
 * less, because the adapter would have accepted it too.
 */
#[CoversClass(DocumentQuery::class)]
final class DocumentQueryTest extends TestCase
{
    // ── The combination rules, each one Firestore's ─────────────────────────

    public function test_not_in_cannot_be_combined_with_ne(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/not-in with !=/');

        DocumentQuery::for('orders')->where(
            DocumentFilter::notIn('status', ['paid', 'void']),
            DocumentFilter::ne('channel', 'web'),
        );
    }

    public function test_not_in_cannot_be_combined_with_in(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/not-in with in or array-contains-any/');

        DocumentQuery::for('orders')->where(
            DocumentFilter::notIn('status', ['void']),
            DocumentFilter::in('channel', ['web', 'app']),
        );
    }

    public function test_only_one_array_contains_is_allowed(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/at most one array-contains/');

        DocumentQuery::for('posts')->where(
            DocumentFilter::arrayContains('tags', 'php'),
            DocumentFilter::arrayContains('tags', 'kernel'),
        );
    }

    public function test_array_contains_cannot_be_combined_with_array_contains_any(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/array-contains with array-contains-any/');

        DocumentQuery::for('posts')->where(
            DocumentFilter::arrayContains('tags', 'php'),
            DocumentFilter::arrayContainsAny('tags', ['kernel', 'ports']),
        );
    }

    // ── The range / ordering coupling ───────────────────────────────────────

    public function test_a_range_filter_forces_the_first_ordering_onto_that_field(): void
    {
        // Legal on MongoDB, rejected by Firestore: "If a query has a range
        // filter, your first ordering must be on the same field."
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/first ordering to be on a range-filtered field/');

        DocumentQuery::for('users')
            ->where(DocumentFilter::gte('age', 18))
            ->orderBy('name');
    }

    public function test_the_same_query_is_accepted_when_the_range_field_is_ordered_first(): void
    {
        $query = DocumentQuery::for('users')
            ->where(DocumentFilter::gte('age', 18))
            ->orderBy('age')
            ->orderBy('name');

        self::assertSame(['age', 'name'], array_keys($query->orderBy));
    }

    public function test_a_range_filter_with_no_ordering_at_all_is_fine(): void
    {
        // Firestore orders by the inequality field implicitly, so there is
        // nothing to refuse. Only an explicit WRONG first ordering is a problem.
        $query = DocumentQuery::for('users')->where(DocumentFilter::gt('age', 18));

        self::assertTrue($query->hasRangeFilter());
        self::assertSame([], $query->orderBy);
    }

    public function test_the_order_is_rechecked_when_a_filter_is_added_after_it(): void
    {
        // The rule depends on both clauses, so adding either one has to
        // re-validate the whole query — not only the clause being added.
        $ordered = DocumentQuery::for('users')->orderBy('name');

        $this->expectException(\InvalidArgumentException::class);
        $ordered->where(DocumentFilter::lt('age', 65));
    }

    // ── Ordering ────────────────────────────────────────────────────────────

    public function test_a_repeated_order_field_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/already ordered by/');

        DocumentQuery::for('users')->orderBy('name')->orderBy('name', SortDirection::Desc);
    }

    public function test_a_zero_limit_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/at least 1/');

        DocumentQuery::for('users')->limit(0);
    }

    public function test_an_empty_cursor_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DocumentQuery::for('users')->after('');
    }

    // ── The existence constraints an adapter has to add ─────────────────────

    public function test_every_ordered_field_is_reported_as_required(): void
    {
        $query = DocumentQuery::for('tasks')->orderBy('priority')->orderBy('due');

        self::assertSame(
            ['priority', 'due'],
            $query->requiredFields(),
            'ordering filters for existence on Firestore, so a Mongo adapter has to add $exists '
            . 'for each ordered field or the two stores return different documents',
        );
    }

    public function test_ne_and_not_in_fields_are_reported_as_required(): void
    {
        $query = DocumentQuery::for('orders')->where(
            DocumentFilter::ne('status', 'paid'),
            DocumentFilter::eq('channel', 'web'),
        );

        self::assertSame(['status'], $query->requiredFields());
        self::assertSame(
            ['status'],
            $query->nonNullFields(),
            '!= additionally excludes an explicit null on Firestore, which $exists alone does not',
        );
    }

    public function test_not_in_requires_existence_but_not_non_nullness(): void
    {
        // The two constraints are genuinely different, which is why they are
        // two methods: not-in excludes a missing field, != excludes a missing
        // field AND an explicit null.
        $query = DocumentQuery::for('orders')
            ->where(DocumentFilter::notIn('status', ['void']));

        self::assertSame(['status'], $query->requiredFields());
        self::assertSame([], $query->nonNullFields());
    }

    public function test_required_fields_are_deduplicated(): void
    {
        $query = DocumentQuery::for('orders')
            ->where(DocumentFilter::ne('status', 'paid'))
            ->orderBy('status');

        self::assertSame(['status'], $query->requiredFields());
    }

    public function test_a_plain_equality_query_requires_nothing(): void
    {
        $query = DocumentQuery::for('orders')->where(DocumentFilter::eq('status', 'paid'));

        self::assertSame([], $query->requiredFields());
        self::assertSame([], $query->nonNullFields());
    }

    // ── Immutability ────────────────────────────────────────────────────────

    public function test_every_builder_method_returns_a_new_query(): void
    {
        $base = DocumentQuery::for('users');

        $withFilter = $base->where(DocumentFilter::eq('active', true));
        $withLimit  = $withFilter->limit(10);

        self::assertSame([], $base->filters, 'the original must not be mutated');
        self::assertCount(1, $withFilter->filters);
        self::assertNull($withFilter->limit);
        self::assertSame(10, $withLimit->limit);
    }

    // ── Collection names ────────────────────────────────────────────────────

    public function test_a_collection_name_may_not_contain_a_slash(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/path separator/');

        DocumentQuery::for('users/profiles');
    }

    public function test_a_collection_name_may_not_contain_a_dollar(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DocumentQuery::for('users$1');
    }

    public function test_an_empty_collection_name_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DocumentQuery::for('');
    }

    public function test_a_reserved_looking_collection_name_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/reserved/');

        DocumentQuery::for('__name__');
    }

    public function test_describe_names_the_clauses_without_leaking_a_value(): void
    {
        $description = DocumentQuery::for('orders')
            ->where(DocumentFilter::eq('customerEmail', 'someone@example.com'))
            ->limit(5)
            ->describe();

        self::assertStringContainsString('orders', $description);
        self::assertStringContainsString('customerEmail eq', $description);
        self::assertStringNotContainsString(
            'someone@example.com',
            $description,
            'a query description lands in logs, so it must never carry the value being searched for',
        );
    }
}
