<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Document;

/**
 * One page of {@see DocumentStorePort::find()} results, and how to ask for the
 * next one.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * WHY THERE IS NO TOTAL COUNT ON THIS OBJECT
 * ────────────────────────────────────────────────────────────────────────────
 * Because neither store gives one away with the page, and pretending otherwise
 * would mean running a second query nobody asked for.
 *
 * On Firestore a count is a separate aggregation request, billed on index
 * entries read — one document read per 1,000 entries, with a minimum of one. So
 * a count attached to every page silently doubles the request count of a list
 * endpoint, and on a large collection costs reads proportional to the number of
 * MATCHES rather than to the page size. On MongoDB `countDocuments` on a
 * filtered query is a scan of the matching index range, which is the same shape
 * of cost.
 *
 * {@see DocumentStorePort::count()} is therefore explicit: a caller that needs
 * "1–20 of 4,312" asks for it and pays for it, and a caller that needs only
 * "next page" — which is most of them — does not.
 *
 * {@see self::hasMore()} answers the question a list page actually has without
 * any of that: it is true when a cursor exists.
 */
final class DocumentPage
{
    /**
     * @param list<DocumentRecord> $records
     * @param string|null          $cursor an opaque position to pass to
     *                                     {@see DocumentQuery::after()}, or null
     *                                     when this is the last page
     */
    private function __construct(
        public readonly array $records,
        public readonly ?string $cursor,
    ) {}

    /**
     * Any array of records; it is reindexed, so a filtered array is fine.
     *
     * Typed as an arbitrary array rather than a list precisely BECAUSE it
     * reindexes: promising a list while calling array_values() would be a
     * contract contradicting its own implementation, and callers would be
     * pushed into writing array_values() twice.
     *
     * @param array<array-key, DocumentRecord> $records
     */
    public static function of(array $records, ?string $cursor = null): self
    {
        if ($cursor === '') {
            // An empty string is not a position, and a caller doing
            // `if ($page->cursor !== null)` would loop forever on it.
            throw new \InvalidArgumentException(
                'A DocumentPage cursor must be a non-empty string or null.',
            );
        }

        return new self(array_values($records), $cursor);
    }

    /**
     * The last page, with nothing after it.
     *
     * @param array<array-key, DocumentRecord> $records
     */
    public static function last(array $records): self
    {
        return self::of($records, null);
    }

    /** No documents at all. */
    public static function empty(): self
    {
        return new self([], null);
    }

    /**
     * Whether another page exists.
     *
     * An adapter MUST NOT return a cursor on a page it knows to be the last —
     * otherwise every caller paginating to exhaustion makes one extra request
     * per listing, and a caller using `while ($page->hasMore())` makes a request
     * that returns nothing before it stops.
     */
    public function hasMore(): bool
    {
        return $this->cursor !== null;
    }

    public function isEmpty(): bool
    {
        return $this->records === [];
    }

    public function count(): int
    {
        return \count($this->records);
    }

    /**
     * The records as plain arrays, each with its id folded in.
     *
     * @return list<array<string, mixed>>
     */
    public function toArray(string $idKey = 'id'): array
    {
        return array_map(
            static fn (DocumentRecord $record): array => $record->toArray($idKey),
            $this->records,
        );
    }
}
