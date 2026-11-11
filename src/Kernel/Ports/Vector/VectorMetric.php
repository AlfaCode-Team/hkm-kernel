<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Vector;

/**
 * How two vectors are compared. Fixed per COLLECTION, never per query.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * WHY THE METRIC BELONGS TO THE COLLECTION
 * ────────────────────────────────────────────────────────────────────────────
 * An approximate index is built for ONE metric. Measured on pgvector 0.8.7 with
 * 30,000 vectors and an HNSW index built with `vector_cosine_ops`: a query using
 * `<=>` (cosine) was served by `Index Scan using t_cos`, and the same query
 * using `<->` (L2) was a `Seq Scan` over all 30,000 rows, with no error and no
 * warning. A port that let each query pick its own metric would let one typo
 * turn a millisecond lookup into a full scan.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * THE DISTANCE CONVENTION: LOWER IS ALWAYS CLOSER
 * ────────────────────────────────────────────────────────────────────────────
 * {@see VectorMatch::$distance} follows pgvector's operators, because they are
 * the only ones measured here:
 *
 *   Cosine       1 - cos(a, b)           0 identical … 1 orthogonal … 2 opposite
 *   Euclidean    the L2 distance, NOT squared
 *   DotProduct   the NEGATIVE inner product
 *
 * The last one is the surprise, and it is measured: `<#>` over [1,0,0] against
 * [2,0,0] returns -2, and against [0,1,0] returns -0. pgvector negates because
 * an index can only serve an ascending ORDER BY. A reader who sees "-2" and
 * takes it for a bug will "fix" it by negating back, and invert every ranking.
 *
 * Another backend that reports similarity (higher is closer) for cosine and dot
 * product must negate or subtract at ITS edge so that a caller never has to ask
 * which backend answered. That conversion is documented here, not measured here:
 * no such backend was run.
 *
 * Excluded: L1, Hamming and Jaccard (not offered by every store a port like this
 * would front), and the half-precision, sparse and bit vector types.
 */
enum VectorMetric: string
{
    case Cosine     = 'cosine';
    case Euclidean  = 'euclidean';
    case DotProduct = 'dot';

    public function label(): string
    {
        return match ($this) {
            self::Cosine     => 'cosine distance',
            self::Euclidean  => 'Euclidean (L2) distance',
            self::DotProduct => 'negative inner product',
        };
    }
}
