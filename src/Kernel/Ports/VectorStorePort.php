<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Ports;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Vector\VectorFilter;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Vector\VectorIndex;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Vector\VectorMatch;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Vector\VectorQuery;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Vector\VectorRecord;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Vector\VectorResult;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Vector\VectorCollection;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Vector\VectorStoreCapability;

/**
 * VectorStorePort — collections of fixed-dimension vectors, searched by
 * similarity, with filters on a flat payload.
 *
 * ════════════════════════════════════════════════════════════════════════════
 * THE DESIGN RULE: THE INTERSECTION, NOT THE UNION
 * ════════════════════════════════════════════════════════════════════════════
 * Same rule as {@see DocumentStorePort}: a method, filter, metric or index type
 * is here only if every store that could sit behind the port can honour it with
 * the same meaning. Where they diverge the port takes the stricter reading and
 * the permissive adapter narrows itself to it.
 *
 * The first adapter is pgvector over {@see DatabasePort}. Everything labelled
 * MEASURED below was run against PostgreSQL 18.6 with pgvector 0.8.7 on
 * 2026-11-11, on a scratch cluster. What is said about any OTHER store is
 * recalled from its documentation and was not run; it is marked as such, and
 * the port should be re-read the day a second adapter is written.
 *
 * ════════════════════════════════════════════════════════════════════════════
 * THE SEVEN CONVERGENCES — OBLIGATIONS ON THE ADAPTER
 * ════════════════════════════════════════════════════════════════════════════
 *
 * 1. A SEARCH RETURNS min(k, MATCHES) — NEVER FEWER BECAUSE OF THE INDEX.
 *    This is the single most important fact about pgvector, and it is the one a
 *    plain `WHERE … ORDER BY e <=> q LIMIT k` gets wrong without any error.
 *    An HNSW scan finds a fixed-size candidate list (`hnsw.ef_search`, 40) and
 *    the `WHERE` is applied AFTER it. Measured, 30,000 vectors, cosine HNSW:
 *
 *      filter matches 2% of rows, k = 10   → Index Scan with `Filter`,
 *                                            "Rows Removed by Filter: 38",
 *                                            returned 2 rows, not 10.
 *      filter matches 0.05% (15 rows), k = 10
 *                                          → the planner STILL chose the HNSW
 *                                            index, scanned 40 candidates,
 *                                            returned 0 rows although 15 match.
 *
 *    No error, no warning. A search that returns nothing for a filter that
 *    matches fifteen records is indistinguishable from "no results".
 *
 *    pgvector 0.8 has `hnsw.iterative_scan` (off by default) that keeps scanning
 *    until k rows pass the filter. Measured: `relaxed_order` returned 10 rows at
 *    the 2% selectivity. It is NOT a complete answer: at 0.05% selectivity with
 *    the planner forced onto the index, `strict_order` returned 8 of the 10
 *    available (15 match) because the scan stopped at `hnsw.max_scan_tuples`
 *    (default 20,000; "Rows Removed by Filter: 19924"). And a GIN index on the
 *    payload does not rescue it: with one present the 2% filter was planned as a
 *    bitmap scan plus exact sort and returned all 10, but the 0.05% filter was
 *    STILL planned onto the HNSW index and returned 0 — the planner has no
 *    statistics for jsonb containment and does not know the filter is rare.
 *
 *    THE OBLIGATION: after an approximate pass that returns fewer than k, the
 *    adapter MUST run an exact pass and return that. The exact pass is
 *    definitive: if it also returns fewer than k, fewer than k records match.
 *    The cost is a scan of the collection, which is why {@see Vector\VectorQuery::exact()}
 *    exists, and why a collection filtered by a very selective field should be
 *    one collection per value instead of one filter.
 *
 * 2. k MAY EXCEED THE INDEX'S SEARCH WIDTH. Measured, default `ef_search = 40`:
 *    `LIMIT 100` over an unfiltered cosine HNSW scan returned FORTY rows. With
 *    `ef_search = 200`, 100. The adapter raises ef_search to at least k — and
 *    `ef_search` tops out at 1000 ("1001 is outside the valid range … (1 .. 1000)"),
 *    which is where {@see Vector\VectorQuery::MAX_K} comes from.
 *
 * 3. THE METRIC IS THE COLLECTION'S. An index serves one operator. Measured:
 *    cosine index + `<=>` → `Index Scan`; cosine index + `<->` → `Seq Scan` over
 *    all 30,000 rows, silently. The adapter derives the operator from the
 *    collection, never from the caller.
 *
 * 4. DISTANCE: LOWER IS CLOSER, FOR EVERY METRIC. See {@see Vector\VectorMetric}.
 *    pgvector's `<#>` is the NEGATIVE inner product (measured: -2 for [2,0,0]·[1,0,0]).
 *
 * 5. ORDER IS (distance, id) — A TOTAL ORDER. Equal distances are common, not
 *    exotic: measured, `[1,0,0]` and `[2,0,0]` are both cosine distance 0 from
 *    `[1,0,0]`. Without a tie-break their order is whatever the scan produced.
 *    pgvector keeps the index: with `ORDER BY e <=> q, id` PostgreSQL 18 plans an
 *    `Incremental Sort` over the same `Index Scan` (it read 11 rows for k = 10 to
 *    see the group boundary), so the tie-break costs almost nothing.
 *
 * 6. THE ZERO VECTOR IS REFUSED IN A COSINE COLLECTION. Measured: `[0,0,0] <=>
 *    [1,0,0]` is NaN, NaN sorts after every real distance, and a zero QUERY
 *    makes every distance NaN. See {@see VectorCollection::assertAccepts()}.
 *    Also refused everywhere: NaN, infinity, and an empty vector.
 *
 * 7. A DIFFERENT DEFINITION IS A CONFLICT, NOT "ALREADY EXISTS". Measured:
 *    `CREATE INDEX IF NOT EXISTS` against an existing index of a different
 *    operator class produced a NOTICE and left the old index in place. See
 *    {@see Vector\VectorOutcome}.
 *
 * ════════════════════════════════════════════════════════════════════════════
 * DIMENSIONS ARE FIXED AND CHECKED TWICE
 * ════════════════════════════════════════════════════════════════════════════
 * The port refuses a mismatched vector before the store sees it
 * ({@see Vector\DimensionMismatchException}). The store refuses it too, with
 * messages that differ by code path (measured: "expected 3 dimensions, not 2" on
 * insert, "different vector dimensions 3 and 2" on a query), so an adapter that
 * skips the port-level check still cannot store a wrong-length vector — but its
 * error would then be a driver error with no collection name in it.
 *
 * Changing an embedding model is not a dimension change that can be absorbed:
 * vectors from two models are not comparable at any length. A new model is a new
 * collection and a full re-embed. `ensureCollection()` returns a conflict when
 * an existing collection's dimension or metric differs, and never reconciles.
 *
 * ════════════════════════════════════════════════════════════════════════════
 * FILTERS
 * ════════════════════════════════════════════════════════════════════════════
 * Eq, In and four numeric ranges, AND-ed. See {@see Vector\VectorFilter} for the
 * rules — a missing field never matches; types are strict; a range never matches
 * a non-number — and for the measurements behind them ({@see VectorFilter::matches()}
 * is the executable specification).
 *
 * ════════════════════════════════════════════════════════════════════════════
 * WHAT THIS PORT DELIBERATELY DOES NOT OFFER
 * ════════════════════════════════════════════════════════════════════════════
 * TRANSACTIONS across calls. {@see self::upsertMany()} is atomic by itself.
 *
 * OFFSET. See {@see Vector\VectorQuery}.
 *
 * NEGATIONS AND OR. See {@see Vector\VectorFilterOperator}.
 *
 * HYBRID (keyword + vector) SEARCH, RE-RANKING, MULTI-VECTOR RECORDS, SPARSE,
 * HALF-PRECISION OR BIT VECTORS, L1/HAMMING/JACCARD. Each is real, and each is
 * something only some stores do.
 *
 * MORE THAN 2000 DIMENSIONS. pgvector cannot index them; see {@see Vector\Vector}.
 *
 * A NULL ADAPTER. Every method either returns data the caller is about to use or
 * writes; a no-op would answer "stored" to a write it discarded. An unbound port
 * fails at the container, which is the honest error.
 *
 * ════════════════════════════════════════════════════════════════════════════
 * TENANCY
 * ════════════════════════════════════════════════════════════════════════════
 * The port has no tenant argument and an adapter must not invent one. Isolation
 * comes from WHICH store the port is bound to (a per-tenant database, by the
 * module that rebinds the underlying connection into the request scope) or from
 * a `tenant_id` payload field the CALLER filters on. The second is the weaker
 * choice, because forgetting the filter leaks, and — by convergence 1 — a
 * selective tenant filter is exactly the case that falls back to a full scan.
 *
 * ════════════════════════════════════════════════════════════════════════════
 * NOT VERIFIED
 * ════════════════════════════════════════════════════════════════════════════
 * No store other than pgvector was run. The claims about "other stores" in this
 * port's value objects are recollections of their documentation. Recall of the
 * approximate pass was measured on one synthetic uniform-random 64-dimension
 * dataset of 30,000 vectors; real embeddings cluster, and HNSW behaves
 * differently on clustered data. No concurrent-write behaviour was measured.
 */
interface VectorStorePort
{
    // ── Collections ─────────────────────────────────────────────────────────

    /**
     * Create the collection, or confirm it already matches.
     *
     * Created → a new, empty collection. AlreadyExists → same dimension AND
     * metric. Conflict → either differs; NOTHING was changed, and the detail
     * says which. Never alters, never migrates, never deletes.
     *
     * @throws Vector\VectorStoreException
     */
    public function ensureCollection(VectorCollection $collection): VectorResult;

    /** The definition as the STORE holds it, or null when there is no such collection. */
    public function collection(string $name): ?VectorCollection;

    /**
     * Delete a collection and everything in it. False when there was none.
     *
     * Irreversible, and a caller who mistypes a name loses data, so an adapter
     * must not accept a pattern or a prefix here.
     *
     * @throws Vector\VectorStoreException
     */
    public function dropCollection(string $name): bool;

    /**
     * Build the approximate index for a collection, or confirm it is the one asked for.
     *
     * One index per collection. A different definition is a Conflict (see
     * convergence 7) — the way to change parameters is {@see self::dropIndex()}
     * then this, so the decision to pay for a rebuild is explicit.
     *
     * @throws Vector\VectorStoreException
     */
    public function ensureIndex(VectorIndex $index): VectorResult;

    /** Drop the collection's index; the data stays and searches become exact scans. False when there was none. */
    public function dropIndex(string $collection): bool;

    // ── Writing ─────────────────────────────────────────────────────────────

    /**
     * Create or REPLACE the record with this id — vector and payload both.
     *
     * Replace, not merge: a payload field absent from the new record is gone.
     * That is the only reading both a document store's `put` and a SQL upsert
     * agree on, and it is what re-embedding an edited chunk wants.
     *
     * @throws Vector\DimensionMismatchException
     * @throws \InvalidArgumentException a zero vector in a cosine collection
     * @throws Vector\VectorStoreException
     */
    public function upsert(string $collection, VectorRecord $record): void;

    /**
     * Upsert many records ATOMICALLY: all are stored or none are.
     *
     * At most {@see self::MAX_BATCH} records, and no two with the same id —
     * "last one wins" and "first one wins" differ between stores, and a caller
     * who sends both versions of an id has a bug the store should not guess at.
     *
     * Validation happens before anything is written, so a bad record 900 of 1000
     * costs nothing.
     *
     * @param list<VectorRecord> $records
     * @return int how many were written (the count of $records)
     * @throws Vector\DimensionMismatchException
     * @throws \InvalidArgumentException duplicate ids, an oversized batch, a zero cosine vector
     * @throws Vector\VectorStoreException
     */
    public function upsertMany(string $collection, array $records): int;

    public const MAX_BATCH = 1000;

    /** Remove one record. False when there was none. */
    public function delete(string $collection, string $id): bool;

    /**
     * Remove every record the filters match; returns how many.
     *
     * At least one filter is REQUIRED. "Delete where nothing is specified" is a
     * truncate, and spelling a truncate as a delete with an empty list is how a
     * variable that came back empty wipes a collection. Use
     * {@see self::dropCollection()} when that is meant.
     *
     * The usual use: every chunk of one source document, `eq('source_id', $id)`,
     * before re-embedding it.
     *
     * @param list<VectorFilter> $filters non-empty
     * @throws \InvalidArgumentException no filters
     * @throws Vector\VectorStoreException
     */
    public function deleteWhere(string $collection, array $filters): int;

    // ── Reading ─────────────────────────────────────────────────────────────

    /**
     * One record by id, vector included, or null when there is none.
     *
     * The returned vector is what the store holds — float32 on pgvector — so it
     * differs from the one written in about the eighth digit.
     *
     * @throws Vector\VectorStoreException when the collection does not exist
     */
    public function get(string $collection, string $id): ?VectorRecord;

    /**
     * How many records the filters match (all of them when there are none).
     *
     * @param list<VectorFilter> $filters
     * @throws Vector\VectorStoreException
     */
    public function count(string $collection, array $filters = []): int;

    /**
     * The k nearest records the filters admit, closest first, ties by id.
     *
     * Returns min(k, matching records) — see convergence 1. May be approximate
     * unless {@see VectorQuery::exact()} was asked for; approximate means a
     * genuinely nearer record can be absent from the result, which is the price
     * of not scanning.
     *
     * @return list<VectorMatch>
     * @throws Vector\DimensionMismatchException
     * @throws Vector\VectorStoreException an exact search on a store that cannot, or a missing collection
     */
    public function search(VectorQuery $query): array;

    public function supports(VectorStoreCapability $capability): bool;

    /**
     * A one-line description for a status command: the adapter and its backend.
     * MUST NOT contain a credential, a connection string or a host password.
     */
    public function describe(): string;
}
