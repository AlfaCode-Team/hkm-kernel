<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Vector;

/**
 * An approximate-nearest-neighbour index on a collection: HNSW, and only HNSW.
 *
 * IVFFlat is pgvector's other index and is not offered, for the reason the
 * document port gives for its ten operators: this is the intersection. It also
 * needs training data to exist BEFORE the index is built (a `lists` count chosen
 * against the row count), so an `ensureIndex()` in a migration would build a
 * useless index on an empty table and report success.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * THE TWO PARAMETERS, AND THEIR MEASURED BOUNDS
 * ────────────────────────────────────────────────────────────────────────────
 * `m` — links per node. pgvector: "value 1 out of bounds for option "m" … Valid
 * values are between "2" and "100"" (measured; default 16).
 *
 * `efConstruction` — build-time candidate list. Measured: between 4 and 1000,
 * and "ef_construction must be greater than or equal to 2 * m" (so m = 16 with
 * efConstruction = 8 is refused although 8 is in range). Default 64.
 *
 * The bounds are enforced HERE so a bad value fails at the call site with the
 * reason, rather than as a driver error from inside `CREATE INDEX`.
 *
 * Query-time effort (`hnsw.ef_search`) is deliberately not a parameter: it is
 * the adapter's job to raise it to at least `k`, see
 * {@see \AlfacodeTeam\PhpServicePlatform\Kernel\Ports\VectorStorePort}.
 */
final readonly class VectorIndex
{
    public const DEFAULT_M               = 16;
    public const DEFAULT_EF_CONSTRUCTION = 64;

    public function __construct(
        public string $collection,
        public int $m = self::DEFAULT_M,
        public int $efConstruction = self::DEFAULT_EF_CONSTRUCTION,
    ) {
        VectorCollection::assertValidName($collection);

        if ($m < 2 || $m > 100) {
            throw new \InvalidArgumentException(\sprintf('HNSW m must be between 2 and 100; %d was given.', $m));
        }

        if ($efConstruction < 4 || $efConstruction > 1000) {
            throw new \InvalidArgumentException(\sprintf(
                'HNSW efConstruction must be between 4 and 1000; %d was given.',
                $efConstruction,
            ));
        }

        if ($efConstruction < 2 * $m) {
            throw new \InvalidArgumentException(\sprintf(
                'HNSW efConstruction must be at least 2 * m (%d); %d was given. pgvector refuses '
                . 'the combination even when each value is individually in range.',
                2 * $m,
                $efConstruction,
            ));
        }
    }

    public function equals(self $other): bool
    {
        return $this->collection === $other->collection
            && $this->m === $other->m
            && $this->efConstruction === $other->efConstruction;
    }
}
