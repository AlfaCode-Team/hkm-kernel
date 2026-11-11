<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Document;

/**
 * What actually happened when an index was requested.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * WHY THIS IS NOT A BOOLEAN
 * ────────────────────────────────────────────────────────────────────────────
 * Because "the call did not fail" and "the index is now what you asked for" are
 * different statements, and an idempotent migration is exactly the place where
 * they come apart.
 *
 * `ensureIndex()` is called on every deploy, so it must treat "this index is
 * already here" as success. The trap is that MongoDB reports a GENUINE conflict
 * through the same door. Measured against MongoDB 9.0.4:
 *
 *   createIndex({a:1}, {name:'a_asc'})                  → ok, 'a_asc'
 *   the identical call again                            → ok, 'a_asc'      (no-op)
 *   createIndex({a:1}, {name:'a_other'})                → code 85  IndexOptionsConflict
 *                                                         "Index already exists with
 *                                                          a different name: a_asc"
 *   createIndex({a:1}, {name:'a_asc', unique:true})     → code 86  IndexKeySpecsConflict
 *
 * The last one is the dangerous case: a migration TIGHTENING an existing index,
 * which is how a new invariant gets enforced. It is refused, and afterwards the
 * index is still there and still NOT unique — confirmed by reading the index
 * back (`unique: false`) and by inserting the duplicate value the new index was
 * supposed to forbid, which was accepted.
 *
 * So an adapter that does the natural thing — swallow "already exists" so the
 * migration is idempotent — converts that refusal into a green deploy with no
 * constraint behind it. The application then runs for as long as it takes
 * somebody to notice duplicates, believing itself protected. MongoDB's own
 * guidance is to "drop the existing index with db.collection.dropIndex() before
 * running db.collection.createIndex() with the new options" — which a migration
 * can only do if it is TOLD the definitions differ.
 *
 * {@see AlreadyExists} and {@see Conflict} are therefore different answers, and
 * an adapter MUST NOT collapse them. A boolean return cannot carry the
 * difference, which is the reason this enum exists.
 *
 * (MongoDB's `createIndex` reference says that re-creating an index with
 * different options "will not change the options nor recreate the index", which
 * reads as a silent success. The measurement above shows 9.0.4 raising an error
 * instead. The port is built on the measurement, and on the part both agree
 * about: the index does not change.)
 *
 * ────────────────────────────────────────────────────────────────────────────
 * AND WHY `Created` IS NOT THE SAME AS `Building`
 * ────────────────────────────────────────────────────────────────────────────
 * Firestore's composite index creation is asynchronous: the REST endpoint
 * `projects.databases.collectionGroups.indexes.create` returns an `Operation`
 * "which may be used to track the status of the creation". So the index does not
 * exist yet when the call returns, and a query that needs it still fails until
 * the build finishes. A migration that creates an index and immediately runs a
 * query depending on it will fail — correctly, and confusingly, unless the
 * outcome said so.
 */
enum IndexOutcome: string
{
    /** The index did not exist and now does, ready to serve queries. */
    case Created = 'created';

    /**
     * The index was accepted and is being built; it cannot serve queries yet.
     *
     * A caller that must not proceed until the index is live has to poll —
     * there is no promise here about how long a build takes, because neither
     * store makes one.
     */
    case Building = 'building';

    /**
     * An identical index already existed. Nothing was done, and nothing needed
     * to be. This is the normal result of re-running a migration.
     */
    case AlreadyExists = 'already_exists';

    /**
     * An index with this name or key exists but DIFFERS from the definition,
     * and the store did NOT change it.
     *
     * The store is not in the state the migration describes. Treat this as a
     * failure that needs a human: the fix is to drop and recreate, which
     * destroys and rebuilds an index and is therefore never done implicitly on
     * a caller's behalf.
     */
    case Conflict = 'conflict';

    /** Whether the index is present and usable right now. */
    public function isUsable(): bool
    {
        return $this === self::Created || $this === self::AlreadyExists;
    }

    /**
     * Whether the store matches the definition that was asked for.
     *
     * True for {@see self::Building} — the definition was accepted, it is just
     * not finished. False only for {@see self::Conflict}, where the stored
     * index and the requested one are different things.
     */
    public function matchesDefinition(): bool
    {
        return $this !== self::Conflict;
    }
}
