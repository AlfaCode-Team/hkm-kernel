<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Vector;

/**
 * What an `ensureCollection()` or `ensureIndex()` call actually did.
 *
 * Not a boolean, for the same reason {@see Document\IndexOutcome} is not: an
 * idempotent migration treats "already there" as success, and the trap is that
 * the store reports a DIFFERENT definition through the same door.
 *
 * Measured on pgvector 0.8.7 / PostgreSQL 18.6, against an HNSW index built with
 * `vector_cosine_ops`, `m = 8`, `ef_construction = 32`:
 *
 *   CREATE INDEX IF NOT EXISTS w_cos … USING hnsw (e vector_l2_ops)
 *       → NOTICE: relation "w_cos" already exists, skipping
 *         and `pg_indexes` afterwards still says vector_cosine_ops, m=8.
 *
 * That is the MongoDB code-86 situation again, with a worse failure mode: no
 * error at all, only a NOTICE that PDO does not surface. A migration changing a
 * collection's index parameters reports success and nothing changed, so queries
 * keep running on the old index — and if the caller thought it was switching
 * METRIC, the new operator no longer matches the index and every query becomes a
 * full scan (see {@see VectorMetric}).
 *
 * So {@see self::AlreadyExists} (identical) and {@see self::Conflict}
 * (different) are separate answers, and an adapter MUST NOT collapse them.
 *
 * The same pgvector run showed the opposite hazard: the SAME definition under a
 * DIFFERENT name creates a second, identical index (three indexes on the table,
 * counted), doubling write cost for nothing. A port that exposes no index NAME —
 * this one does not — cannot be asked to do that.
 */
enum VectorOutcome: string
{
    /** Built and ready to serve. */
    case Created = 'created';

    /** Accepted and still building; queries may fall back to a scan until it finishes. */
    case Building = 'building';

    /** An identical one was already present. */
    case AlreadyExists = 'already_exists';

    /** A DIFFERENT one is present. Nothing was changed. */
    case Conflict = 'conflict';

    public function matchesDefinition(): bool
    {
        return $this !== self::Conflict;
    }
}
