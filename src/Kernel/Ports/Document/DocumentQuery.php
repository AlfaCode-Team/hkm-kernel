<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Document;

/**
 * An immutable, portable query over one collection.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * WHAT THIS CLASS IS FOR
 * ────────────────────────────────────────────────────────────────────────────
 * It is not a convenience wrapper. It is the place where a query that only ONE
 * document store can run is refused, with the reason, on the machine of whoever
 * wrote it.
 *
 * The alternative — an adapter that translates whatever it is handed — fails in
 * a specific and expensive way. Firestore's restrictions are much tighter than
 * MongoDB's, so a query written and tested against Mongo compiles, passes, ships,
 * and then throws `FAILED_PRECONDITION` the first time it runs against Firestore.
 * Nothing in the test suite can catch that, because the test suite runs against
 * whichever adapter is configured. So the rules are enforced by the QUERY, which
 * is the one component both adapters share.
 *
 * Every rule below is one of Firestore's published restrictions. MongoDB can run
 * all of them; it simply permits more. The port offers the intersection.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * THE RULE MOST LIKELY TO SURPRISE: ORDERING IS ALSO A FILTER
 * ────────────────────────────────────────────────────────────────────────────
 * Firestore: "An `orderBy()` clause also filters for existence of the given
 * fields. The result set will not include documents that do not contain the
 * given fields."
 *
 * So ordering a collection by `priority` DROPS every document that has no
 * `priority`. MongoDB keeps them and sorts them first. In a store with no
 * schema — which is the only kind this port talks to — an absent optional field
 * is the normal case, so this is not an edge: the same code returns a shorter
 * list on Firestore than on Mongo, silently, and a list page missing some of
 * its rows looks exactly like a list page.
 *
 * {@see \AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DocumentStorePort} requires
 * every adapter to converge on this reading, and {@see self::requiredFields()}
 * tells an adapter which existence constraints to add. The port cannot offer
 * "sort but keep documents lacking the field" because Firestore cannot express
 * it — so rather than offering it on one store only, it is not offered.
 */
final class DocumentQuery
{
    /**
     * @param list<DocumentFilter>            $filters
     * @param array<string, SortDirection>    $orderBy field path => direction, in order
     */
    private function __construct(
        public readonly string $collection,
        public readonly array $filters,
        public readonly array $orderBy,
        public readonly ?int $limit,
        public readonly ?string $after,
    ) {
        $this->assertCoherent();
    }

    /** Start a query over $collection. */
    public static function for(string $collection): self
    {
        CollectionName::assertValid($collection);

        return new self($collection, [], [], null, null);
    }

    /** Add a predicate. Filters combine with AND; see {@see DocumentFilter}. */
    public function where(DocumentFilter ...$filters): self
    {
        return new self(
            $this->collection,
            [...$this->filters, ...array_values($filters)],
            $this->orderBy,
            $this->limit,
            $this->after,
        );
    }

    /**
     * Append an ordering.
     *
     * Remember that this also requires the field to EXIST — see the class
     * docblock. Ordering by an optional field is a filter on that field.
     */
    public function orderBy(string $field, SortDirection $direction = SortDirection::Asc): self
    {
        FieldPath::assertValid($field, 'order field');

        if (isset($this->orderBy[$field])) {
            throw new \InvalidArgumentException(\sprintf(
                'The query is already ordered by "%s". A repeated order field is a no-op on one '
                . 'store and an error on another, so it is refused here.',
                $field,
            ));
        }

        return new self(
            $this->collection,
            $this->filters,
            [...$this->orderBy, $field => $direction],
            $this->limit,
            $this->after,
        );
    }

    /** Return at most $limit documents. */
    public function limit(int $limit): self
    {
        if ($limit < 1) {
            throw new \InvalidArgumentException(\sprintf(
                'A limit must be at least 1; %d was given. A zero limit reads as "no results" to '
                . 'every caller, so it is refused rather than executed.',
                $limit,
            ));
        }

        return new self($this->collection, $this->filters, $this->orderBy, $limit, $this->after);
    }

    /**
     * Continue after a cursor from a previous {@see DocumentPage}.
     *
     * ────────────────────────────────────────────────────────────────────────
     * THERE IS NO `skip()` / `offset()`, AND THAT IS THE POINT
     * ────────────────────────────────────────────────────────────────────────
     * Firestore's query API does have an offset, so this port could offer one.
     * It does not, because of how that offset is charged: "when you send a query
     * that includes an offset, you are charged a read for each skipped
     * document" — "if your query uses an offset of 10, and the query returns 1
     * document, you are charged for 11 reads."
     *
     * Page-number pagination over an offset is therefore O(n) in the page
     * number. Page 500 of a 20-per-page report costs 10,000 reads to return 20
     * documents, and the bill is the only place that shows up. MongoDB has the
     * same shape of problem for a different reason — `skip` walks the documents
     * it discards — so this is not a Firestore quirk being papered over.
     *
     * A cursor is O(1) on both stores. The cost of offering only cursors is that
     * a caller cannot jump to page 500; the cost of offering offsets is a query
     * whose price grows silently with how deep a user scrolls.
     */
    public function after(string $cursor): self
    {
        if ($cursor === '') {
            throw new \InvalidArgumentException(
                'An empty cursor is not a position. Omit after() for the first page.',
            );
        }

        return new self($this->collection, $this->filters, $this->orderBy, $this->limit, $cursor);
    }

    /** Whether any filter is a range comparison. */
    public function hasRangeFilter(): bool
    {
        foreach ($this->filters as $filter) {
            if ($filter->isRange()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every field this query requires to EXIST on a matching document.
     *
     * An adapter whose store does not filter for existence by itself MUST add
     * these — a MongoDB adapter translates each into `{field: {$exists: true}}`,
     * and for a `ne`/`not-in` field additionally `{field: {$ne: null}}`. That is
     * what keeps one query meaning one thing on both stores.
     *
     * The list is the union of:
     *   - every ordered field (ordering filters for existence);
     *   - every field compared with `!=` or `not-in` (both exclude a missing
     *     field, and `!=` also excludes an explicit null).
     *
     * @return list<string>
     */
    public function requiredFields(): array
    {
        $fields = array_keys($this->orderBy);

        foreach ($this->filters as $filter) {
            if ($filter->operator->excludesMissingField()) {
                $fields[] = $filter->field;
            }
        }

        return array_values(array_unique($fields));
    }

    /**
     * Fields compared with `!=`, which must additionally not be null.
     *
     * Separate from {@see self::requiredFields()} because the two constraints
     * differ: `not-in` excludes a missing field, while `!=` excludes a missing
     * field AND an explicit null.
     *
     * @return list<string>
     */
    public function nonNullFields(): array
    {
        $fields = [];

        foreach ($this->filters as $filter) {
            if ($filter->operator === DocumentOperator::Ne) {
                $fields[] = $filter->field;
            }
        }

        return array_values(array_unique($fields));
    }

    /** A one-line description for a log or an exception. Never a filter value. */
    public function describe(): string
    {
        $parts = [$this->collection];

        foreach ($this->filters as $filter) {
            $parts[] = 'where ' . $filter->describe();
        }

        foreach ($this->orderBy as $field => $direction) {
            $parts[] = 'order ' . $field . ' ' . $direction->value;
        }

        if ($this->limit !== null) {
            $parts[] = 'limit ' . $this->limit;
        }

        if ($this->after !== null) {
            $parts[] = 'after cursor';
        }

        return implode(' ', $parts);
    }

    /**
     * Enforce every cross-clause rule, on every mutation.
     *
     * Re-run in full rather than incrementally: the range/ordering rule depends
     * on both the filters and the orderings, so adding either can break a
     * combination that was legal a moment ago. Validating the whole object is
     * cheap and cannot drift out of step with itself.
     */
    private function assertCoherent(): void
    {
        $operators     = [];
        $rangeFields   = [];
        $arrayContains = 0;

        foreach ($this->filters as $filter) {
            $operators[$filter->operator->value] = true;

            if ($filter->isRange()) {
                $rangeFields[$filter->field] = true;
            }

            if ($filter->operator === DocumentOperator::ArrayContains) {
                $arrayContains++;
            }
        }

        // Firestore: "You can't combine not-in with not equals !=."
        if (isset($operators['not_in'], $operators['ne'])) {
            throw new \InvalidArgumentException(
                'A query may not combine not-in with != — Firestore refuses it. Express the '
                . 'exclusion as a single not-in over at most 10 values.',
            );
        }

        // Firestore: not-in cannot be combined with in, array-contains-any or or.
        if (isset($operators['not_in']) && (isset($operators['in']) || isset($operators['array_contains_any']))) {
            throw new \InvalidArgumentException(
                'A query may not combine not-in with in or array-contains-any — Firestore refuses '
                . 'it. Run the positive query and subtract in PHP.',
            );
        }

        // Firestore: at most one array-contains per disjunction, and a query
        // with no disjunctions has exactly one.
        if ($arrayContains > 1) {
            throw new \InvalidArgumentException(\sprintf(
                'A query may use at most one array-contains; %d were given. Firestore allows one '
                . 'per or-group, and this port has no or-groups.',
                $arrayContains,
            ));
        }

        // Firestore: array-contains cannot be combined with array-contains-any
        // in the same disjunction.
        if (isset($operators['array_contains'], $operators['array_contains_any'])) {
            throw new \InvalidArgumentException(
                'A query may not combine array-contains with array-contains-any — Firestore refuses '
                . 'it. array-contains-any with the single value expresses the same thing.',
            );
        }

        // Firestore: "If a query has a range filter, your first ordering must be
        // on the same field."
        //
        // With no ordering at all this is satisfied implicitly: Firestore orders
        // by the inequality field itself. It is an EXPLICIT first ordering on
        // some OTHER field that is refused — and that combination is perfectly
        // legal on MongoDB, which is exactly why it has to fail here.
        if ($rangeFields !== [] && $this->orderBy !== []) {
            $first = array_key_first($this->orderBy);

            if (!isset($rangeFields[$first])) {
                throw new \InvalidArgumentException(\sprintf(
                    'This query range-filters on %s but orders first by "%s". Firestore requires the '
                    . 'first ordering to be on a range-filtered field, so order by %s first and add '
                    . '"%s" after it. (MongoDB would run this as written, which is why it is refused '
                    . 'here rather than in the adapter.)',
                    '"' . implode('", "', array_keys($rangeFields)) . '"',
                    $first,
                    '"' . implode('" or "', array_keys($rangeFields)) . '"',
                    $first,
                ));
            }
        }
    }
}
