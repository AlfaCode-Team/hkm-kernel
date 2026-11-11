<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Ports;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Document\DocumentIndex;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Document\DocumentPage;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Document\DocumentQuery;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Document\DocumentRecord;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Document\DocumentStoreCapability;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Document\IndexResult;

/**
 * DocumentStorePort — a collection of schemaless documents, addressed by id.
 *
 * ════════════════════════════════════════════════════════════════════════════
 * WHY THIS IS NOT DatabasePort
 * ════════════════════════════════════════════════════════════════════════════
 * {@see DatabasePort} takes SQL strings. That is not a limitation to work
 * around — it is what makes it portable across MySQL, PostgreSQL and SQLite,
 * because those three answer the same language. MongoDB and Firestore answer no
 * language at all; they take structured requests, with different structures,
 * and neither has a text form the other would parse.
 *
 * So a document store cannot hide behind `DatabasePort`, and the two are not
 * alternatives: an application generally has both, with relational tables for
 * the data that has a shape and documents for the data that does not.
 *
 * ════════════════════════════════════════════════════════════════════════════
 * THE DESIGN RULE: THE INTERSECTION, NOT THE UNION
 * ════════════════════════════════════════════════════════════════════════════
 * Every method here is one that BOTH stores can honour with the same meaning.
 * Where they diverge, the port takes the stricter reading and requires the more
 * permissive adapter to narrow itself to it.
 *
 * That direction is not arbitrary. Narrowing the permissive store makes a query
 * return FEWER documents than it could have; widening the strict one is simply
 * not possible — Firestore has no way to express "sort by this field and also
 * keep documents that lack it". One of those options exists, so the choice is
 * between one meaning everywhere and two meanings depending on deployment. Two
 * meanings is how a feature passes every test and then misbehaves in production.
 *
 * ════════════════════════════════════════════════════════════════════════════
 * THE THREE CONVERGENCES AN ADAPTER MUST IMPLEMENT
 * ════════════════════════════════════════════════════════════════════════════
 * These are obligations on the ADAPTER, not suggestions. A MongoDB adapter that
 * skips them is not a faster adapter; it is a different port.
 *
 * 1. ORDERING FILTERS FOR EXISTENCE. Firestore: "An `orderBy()` clause also
 *    filters for existence of the given fields. The result set will not include
 *    documents that do not contain the given fields." MongoDB instead sorts a
 *    missing field as null and keeps the document. An adapter whose store does
 *    not drop those documents MUST add the existence constraint itself —
 *    {@see DocumentQuery::requiredFields()} lists exactly which fields.
 *
 *    This is the single most likely cause of a defect through this port. In a
 *    store with no schema an optional field is absent on real documents as a
 *    matter of course, so "order a list by priority" quietly returns a SHORTER
 *    list on Firestore — and a list page missing some of its rows looks exactly
 *    like a list page. No error is raised on either store.
 *
 *    Measured on MongoDB 9.0.4 over five documents, one of which has no
 *    `priority`: sorting by `priority` returns all FIVE, the one missing the
 *    field sorted first. Adding the mandated `{priority: {$exists: true}}`
 *    returns FOUR — which is what Firestore would have returned. The gap is one
 *    silently absent document per query, and it is the document whose field was
 *    never set.
 *
 * 2. `!=` AND `not-in` EXCLUDE A MISSING FIELD. Firestore: "`!=` and `not-in`
 *    exclude documents where the field doesn't exist", and "Documents with a
 *    `null` value also don't match `!=`". In MongoDB `{status: {$ne: 'paid'}}`
 *    matches a document with no `status` at all, and one where it is null,
 *    because a missing field compares as null and null is not 'paid'. So the
 *    predicate most likely to be written as a safety net — "everything not yet
 *    paid" — means opposite things on the two stores for exactly the documents
 *    where it matters. {@see DocumentQuery::requiredFields()} and
 *    {@see DocumentQuery::nonNullFields()} give the constraints to add.
 *
 *    Measured on MongoDB 9.0.4: `{status: {$ne: 'paid'}}` matched FOUR
 *    documents, including one with no `status` field and one with `status:
 *    null`. With the mandated `$exists: true` and `$ne: null` added it matched
 *    TWO — the documents that actually have a status which is not 'paid'. So
 *    "everything not yet paid" silently includes records that were never given
 *    a status at all, and a job draining that query would act on them.
 *
 * 3. A CURSOR NEEDS A TOTAL ORDER. {@see DocumentQuery} does not require the
 *    ordering to be unique, because requiring it would make the common case
 *    ("newest first") unexpressible. Keyset pagination over a non-unique sort
 *    key repeats or skips rows at every page boundary where values tie. An
 *    adapter MUST therefore append the document id as the final sort key
 *    whenever it serves a query — Firestore orders by `__name__`, MongoDB by
 *    `_id`, and both are unique by construction. The caller never sees it; the
 *    pages simply stop overlapping.
 *
 * ════════════════════════════════════════════════════════════════════════════
 * WHAT THIS PORT DELIBERATELY DOES NOT OFFER
 * ════════════════════════════════════════════════════════════════════════════
 * TRANSACTIONS. Both stores have them, which is exactly why this is a decision
 * rather than an omission. Firestore's are always available. MongoDB's are
 * "supported on replica sets and sharded clusters", with a minimum feature
 * compatibility version of 4.0 for a replica set and 4.2 for a sharded cluster
 * — which excludes the single standalone `mongod` that a developer installs on
 * a laptop. Confirmed by attempting one against a standalone MongoDB 9.0.4: it
 * is refused, with the deployment reporting that it "does not support retryable
 * writes".
 *
 * A `transaction()` on this port would therefore work in production and fail in
 * development, or the reverse, depending on how each environment happened to be
 * provisioned — and the failure would land on whoever was least able to explain
 * it. That is the worst available outcome, so it is not offered. Adding it
 * later, behind a capability, stays possible; removing it once callers depend on
 * it does not.
 *
 * OFFSET PAGINATION. See {@see DocumentQuery::after()} — Firestore charges a
 * read for every skipped document, so a page number is priced in proportion to
 * how far a user scrolled.
 *
 * A RICHER QUERY GRAMMAR. See {@see Document\DocumentOperator} — the ten
 * operators are the intersection of the two query languages.
 *
 * A NULL ADAPTER. {@see PubSubPort} has {@see NullPubSub} because `publish()` is
 * fire-and-forget: reporting something that already happened must not cost the
 * response. A document store has no such operation. Every method here either
 * returns data the caller is about to use or performs a write, so a no-op
 * implementation would answer "saved" to a write it discarded. There is nothing
 * safe for it to do, so it does not exist, and an unbound port fails at the
 * container as a missing binding — which is the honest error.
 *
 * ════════════════════════════════════════════════════════════════════════════
 * DOCUMENT IDS
 * ════════════════════════════════════════════════════════════════════════════
 * An id is an opaque non-empty string. {@see self::insert()} does not take one
 * and never will: a store-generated id is the only kind both stores agree on,
 * and it is also what Firestore's own guidance calls for — sequential ids such
 * as `Customer1, Customer2` "can lead to hotspots that impact latency", while
 * "Cloud Firestore allocates document IDs using a scatter algorithm".
 *
 * When the id must be chosen by the caller — an idempotency key, a natural key —
 * {@see self::create()} and {@see self::put()} take one, and their semantics
 * ("fail if present" / "create or replace") are unambiguous on both stores.
 */
interface DocumentStorePort
{
    // ── Reading ─────────────────────────────────────────────────────────────

    /**
     * One document by id, or null when there is none.
     *
     * Null is an ANSWER, not an error: asking whether a document exists is a
     * normal thing to do, and a store that threw would force every caller to
     * wrap the call in a try.
     *
     * @param non-empty-string $collection
     * @param non-empty-string $id
     * @throws Document\DocumentStoreException if the store could not be reached
     */
    public function get(string $collection, string $id): ?DocumentRecord;

    /**
     * Run a query and return one page.
     *
     * The page carries a cursor when more documents follow. An adapter MUST NOT
     * return a cursor on a page it knows to be the last — see
     * {@see DocumentPage::hasMore()}.
     *
     * @throws Document\DocumentStoreException if the store refused or could not
     *         be reached. On Firestore a missing composite index is reported
     *         this way, and the adapter should pass through the index-creation
     *         URL the store supplies, because that URL is the fastest fix.
     */
    public function find(DocumentQuery $query): DocumentPage;

    /**
     * How many documents the query matches, ignoring its limit and cursor.
     *
     * Separate from {@see self::find()} and never implied by it, because it is
     * a second request with its own cost. On Firestore a count is billed on
     * index entries read — one document read per 1,000 entries, minimum one —
     * so it scales with the number of MATCHES, not with the page size. On
     * MongoDB it walks the matching index range. Neither is free, and a count
     * attached to every list page would double the request count of every
     * listing in the application.
     *
     * @throws Document\DocumentStoreException
     */
    public function count(DocumentQuery $query): int;

    // ── Writing ─────────────────────────────────────────────────────────────

    /**
     * Add a document under a store-generated id, and return that id.
     *
     * @param non-empty-string     $collection
     * @param array<string, mixed> $document
     * @return non-empty-string the new id
     * @throws Document\DocumentStoreException
     */
    public function insert(string $collection, array $document): string;

    /**
     * Add a document under a CHOSEN id, failing if one already exists there.
     *
     * Returns false when the id is taken — an answer, not an exception, because
     * the whole purpose of a chosen id is usually idempotency: "insert this if
     * it is not already there" is a successful outcome either way, and a caller
     * replaying a webhook should not have to catch an exception to learn that
     * its first attempt worked.
     *
     * @param non-empty-string     $collection
     * @param non-empty-string     $id
     * @param array<string, mixed> $document
     * @return bool true when created, false when the id already existed
     * @throws Document\DocumentStoreException
     */
    public function create(string $collection, string $id, array $document): bool;

    /**
     * Create or completely REPLACE the document at $id.
     *
     * Replace, not merge: fields absent from $document are gone afterwards.
     * {@see self::patch()} is the merging operation. The distinction is spelled
     * out because both stores offer both and the method names differ in each,
     * so "update" is the one word that means something different everywhere.
     *
     * @param non-empty-string     $collection
     * @param non-empty-string     $id
     * @param array<string, mixed> $document
     * @throws Document\DocumentStoreException
     */
    public function put(string $collection, string $id, array $document): void;

    /**
     * Merge $set into an EXISTING document, and remove $unset.
     *
     * Returns false when there is no document at $id — nothing is created. Both
     * stores behave this way for their merge operation, so the port does not
     * have to choose.
     *
     * Keys in $set are dotted paths, so `['address.city' => 'Kampala']` changes
     * one nested field and leaves the rest of `address` alone.
     *
     * A null VALUE in $set stores a null; it does not remove the field. Removal
     * is $unset, and the two are separate parameters rather than a magic
     * sentinel because a sentinel value is indistinguishable from data that
     * happens to equal it.
     *
     * @param non-empty-string     $collection
     * @param non-empty-string     $id
     * @param array<string, mixed> $set    dotted path => new value
     * @param list<string>         $unset  dotted paths to remove
     * @return bool true when a document was updated, false when $id was absent
     * @throws Document\DocumentStoreException
     */
    public function patch(string $collection, string $id, array $set, array $unset = []): bool;

    /**
     * Add $by to a numeric field, atomically.
     *
     * Present on the port because it is the one operation that genuinely cannot
     * be built out of the others: read-modify-write loses concurrent increments,
     * and the port has no transactions to make it safe. Both stores implement it
     * server-side — MongoDB `$inc`, Firestore a numeric increment transform.
     *
     * The field is created, holding $by, if it is absent. The document is NOT
     * created: a missing document returns false.
     *
     * ────────────────────────────────────────────────────────────────────────
     * THE ONE DIVERGENCE THIS PORT DOES NOT CONVERGE, AND WHY
     * ────────────────────────────────────────────────────────────────────────
     * If the field already exists holding something that is NOT a number, the
     * two stores do opposite things and this port lets them.
     *
     *   MongoDB  refuses the write. Measured on 9.0.4: `$inc` against a string
     *            field fails, and the counter keeps its old value.
     *   Firestore overwrites it. Its own reference: "If the field is not an
     *            integer or double, or if the field does not yet exist, the
     *            field is set to the given value instead."
     *
     * Everywhere else this port narrows the permissive store onto the strict
     * one. It cannot here. Detecting the field's type on Firestore would mean
     * reading the document first and writing conditionally — which is exactly
     * the read-modify-write that loses concurrent increments, and atomicity is
     * the only reason this method exists. Converging would therefore destroy the
     * guarantee being converged.
     *
     * So the rule for a caller is the one the divergence cannot take away: a
     * field used as a counter must only ever be written as a number. A counter
     * that was once stored as the string "0" is already broken, and the stores
     * merely disagree about how loudly.
     *
     * An adapter MUST NOT paper over this. It reports what its store did.
     *
     * @param non-empty-string $collection
     * @param non-empty-string $id
     * @param non-empty-string $field dotted path
     * @return bool true when a document was updated, false when $id was absent
     * @throws Document\DocumentStoreException if the store refused the write —
     *         which MongoDB does for a non-numeric field and Firestore does not
     */
    public function increment(string $collection, string $id, string $field, int|float $by = 1): bool;

    /**
     * Remove the document at $id.
     *
     * @param non-empty-string $collection
     * @param non-empty-string $id
     * @return bool true when a document was removed, false when none existed
     * @throws Document\DocumentStoreException
     */
    public function delete(string $collection, string $id): bool;

    // ── Schema: the "migration" surface ─────────────────────────────────────

    /**
     * Make sure $index exists, and report what that took.
     *
     * IDEMPOTENT BY CONTRACT — this is called on every deploy, so a second run
     * must be a no-op rather than an error.
     *
     * READ {@see IndexResult} AND DO NOT DISCARD IT. The return value is not a
     * courtesy: MongoDB documents that re-creating an index with different
     * options "will not change the options nor recreate the index", and the call
     * SUCCEEDS. A migration that tightens an index — adding `unique` to enforce
     * a new invariant — therefore reports success while changing nothing, and
     * the application proceeds believing a constraint exists. That case is
     * {@see Document\IndexOutcome::Conflict}, and a runner that ignores the
     * result cannot see it.
     *
     * Creation may be ASYNCHRONOUS. Firestore's index-creation endpoint returns
     * a long-running operation, so the index does not exist yet when this
     * returns and a query needing it still fails. That is
     * {@see Document\IndexOutcome::Building} — distinct from
     * {@see Document\IndexOutcome::Created} for exactly that reason.
     *
     * @throws Document\DocumentStoreException if the definition needs a
     *         capability this adapter does not have — see
     *         {@see Document\DocumentStoreException::unsupported()}. An
     *         unsupported constraint is NEVER downgraded to one the store can
     *         manage: a plain index where a unique one was asked for removes an
     *         invariant without removing the belief in it.
     */
    public function ensureIndex(DocumentIndex $index): IndexResult;

    /**
     * Remove an index by name.
     *
     * @param non-empty-string $collection
     * @param non-empty-string $name as {@see DocumentIndex::$name} derived it
     * @return bool true when an index was dropped, false when none had that name
     * @throws Document\DocumentStoreException
     */
    public function dropIndex(string $collection, string $name): bool;

    /**
     * The names of the indexes on $collection.
     *
     * For a status command and for a migration that needs to see what is there.
     * Names only: the two stores describe an index so differently that a common
     * shape would either lose information or invent it.
     *
     * @param non-empty-string $collection
     * @return list<string>
     * @throws Document\DocumentStoreException
     */
    public function listIndexes(string $collection): array;

    // ── Introspection ───────────────────────────────────────────────────────

    /**
     * Whether this adapter can enforce $capability.
     *
     * For a caller that can legitimately adapt — a status command reporting what
     * the deployment enforces, or a feature that keeps its own uniqueness
     * lookup when the store has no unique index. NOT a licence to skip a
     * constraint silently: {@see self::ensureIndex()} throws precisely so that
     * skipping has to be a decision somebody wrote down.
     */
    public function supports(DocumentStoreCapability $capability): bool;

    /**
     * Which store this is, and where it points — for a status command.
     *
     * NEVER a credential, a connection string with a password, or a service
     * account key. "MongoDB 7.0 at cluster0.example.net/app" is the shape; a
     * status command is the thing most likely to be pasted into a bug report.
     */
    public function describe(): string;
}
