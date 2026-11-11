<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Vector;

/**
 * A vector's length differs from its collection's.
 *
 * An `\InvalidArgumentException` rather than a {@see VectorStoreException}: it
 * is a caller error that no retry can fix, and it is raised BEFORE the store is
 * asked. The store would refuse it too — pgvector reports "expected 3
 * dimensions, not 2" on a write and "different vector dimensions 3 and 2" on a
 * query, measured — but those are two different messages from two different
 * code paths, and an adapter that let a query through to the database would
 * discover the mismatch only after paying for a round trip.
 *
 * The usual cause is changing the embedding model without changing the
 * collection. That is not a rounding problem: vectors from two models are not
 * comparable even at the same length, so the remedy is a new collection and a
 * re-embed, never a pad or a truncate.
 */
final class DimensionMismatchException extends \InvalidArgumentException
{
    public function __construct(
        public readonly string $collection,
        public readonly int $expected,
        public readonly int $actual,
    ) {
        parent::__construct(\sprintf(
            'Collection "%s" holds %d-dimension vectors; this vector has %d. If the embedding model '
            . 'changed, the stored vectors are not comparable to the new ones even at the same '
            . 'length — create a new collection and re-embed rather than padding or truncating.',
            $collection,
            $expected,
            $actual,
        ));
    }
}
