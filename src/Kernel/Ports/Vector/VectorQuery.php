<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Vector;

/**
 * A k-nearest-neighbour search: a collection, a query vector, k, and filters.
 *
 * Built with {@see self::nearest()} and refined with immutable `with*` methods.
 * Everything a store cannot honour is refused HERE, at construction, never
 * discovered at the store — the same discipline as {@see Document\DocumentQuery}.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * `k` IS CAPPED AT 1000, AND THAT IS pgvector's CEILING
 * ────────────────────────────────────────────────────────────────────────────
 * `hnsw.ef_search` accepts 1..1000 (measured: "1001 is outside the valid range
 * for parameter "hnsw.ef_search" (1 .. 1000)"), and an HNSW scan returns at most
 * ef_search rows unless iterative scanning is on. Measured with the default of
 * 40: `ORDER BY e <=> q LIMIT 100` returned FORTY rows, silently. An adapter
 * raises ef_search to k — and so cannot serve a k above the ceiling.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * THERE IS NO OFFSET
 * ────────────────────────────────────────────────────────────────────────────
 * "Page 3 of nearest neighbours" is not a meaningful operation on an
 * approximate index: the candidate set changes with ef_search, and page 3 is a
 * different query from pages 1 and 2. Ask for a larger k.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * THERE IS NO METRIC
 * ────────────────────────────────────────────────────────────────────────────
 * It belongs to the collection. See {@see VectorMetric}.
 */
final readonly class VectorQuery
{
    public const MAX_K       = 1000;
    public const MAX_FILTERS = 16;

    /** @param list<VectorFilter> $filters */
    private function __construct(
        public string $collection,
        public Vector $vector,
        public int $k,
        public array $filters,
        public bool $exact,
        public bool $includePayload,
    ) {
        VectorCollection::assertValidName($collection);

        if ($k < 1 || $k > self::MAX_K) {
            throw new \InvalidArgumentException(\sprintf(
                'k must be between 1 and %d; %d was given. The ceiling is the largest hnsw.ef_search '
                . 'pgvector accepts: an index scan cannot return more candidates than that.',
                self::MAX_K,
                $k,
            ));
        }

        if (\count($filters) > self::MAX_FILTERS) {
            throw new \InvalidArgumentException(\sprintf('A query takes at most %d filters.', self::MAX_FILTERS));
        }
    }

    public static function nearest(string $collection, Vector $vector, int $k = 10): self
    {
        return new self($collection, $vector, $k, [], false, true);
    }

    /** Add AND-ed conditions. */
    public function where(VectorFilter ...$filters): self
    {
        return new self(
            $this->collection,
            $this->vector,
            $this->k,
            [...$this->filters, ...array_values($filters)],
            $this->exact,
            $this->includePayload,
        );
    }

    /**
     * Forbid approximation: every record the filters admit is considered.
     *
     * Costs a scan of the collection. Use it to measure the recall of the default
     * mode, for small collections, and for a filter so selective that the index
     * cannot help — which {@see \AlfacodeTeam\PhpServicePlatform\Kernel\Ports\VectorStorePort::search()}
     * already falls back to on its own when an approximate pass comes up short.
     */
    public function exact(): self
    {
        return new self($this->collection, $this->vector, $this->k, $this->filters, true, $this->includePayload);
    }

    public function withoutPayload(): self
    {
        return new self($this->collection, $this->vector, $this->k, $this->filters, $this->exact, false);
    }
}
