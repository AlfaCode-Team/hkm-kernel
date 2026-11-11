<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Document;

/**
 * A schema feature that SOME document stores enforce and others cannot.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * WHY CAPABILITIES EXIST HERE AND NOWHERE ELSE IN THE PORT
 * ────────────────────────────────────────────────────────────────────────────
 * Queries are handled by narrowing: {@see DocumentQuery} offers only what every
 * store can run, so there is nothing to feature-detect. That approach does not
 * work for schema, because the features involved are not conveniences — they
 * are CONSTRAINTS, and a constraint that is quietly absent is worse than one
 * that was never requested.
 *
 * A unique index is the clearest case. Ask for one, get a no-op, and the
 * application now believes the store is enforcing an invariant that nothing
 * enforces. Every write that should have been rejected succeeds. The data is
 * wrong from then on, there is no error anywhere, and the damage is already
 * persisted by the time anybody queries for duplicates.
 *
 * So an unsupported capability is never silently skipped. {@see
 * \AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DocumentStorePort::ensureIndex()}
 * throws when a definition asks for something the adapter has not declared, and
 * the migration fails loudly at deploy time — which is the only moment at which
 * the problem is still cheap.
 *
 * Checked with {@see \AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DocumentStorePort::supports()}
 * so a caller that can degrade gracefully may choose to, deliberately.
 */
enum DocumentStoreCapability: string
{
    /**
     * The store rejects a write that would duplicate an indexed value.
     *
     * MongoDB: a `unique: true` index.
     *
     * Firestore: the published reference for the standard edition does not
     * offer one, and the usual answer is to make the unique value the document
     * ID or to keep a lookup collection written inside a transaction. This port
     * does NOT hard-code that, because a capability flag is correct whichever
     * way an edition or a future release lands — the adapter declares what it
     * can actually do, and a wrong assumption in the kernel would be far harder
     * to discover than a wrong declaration in one adapter.
     */
    case UniqueIndex = 'unique_index';

    /**
     * The store validates document structure on write, server-side.
     *
     * MongoDB: a `$jsonSchema` validator with a validation level and action.
     *
     * Firestore: not reachable from this port. Firestore's structural
     * enforcement is Security Rules, and its own documentation states that
     * "server client libraries bypass all Cloud Firestore Security Rules" —
     * which is precisely how a PHP adapter authenticates. So rules may be
     * written and may constrain mobile clients, while every write this port
     * makes passes straight through them. A validator that an adapter cannot
     * apply to its OWN writes is not a validator, and claiming it would be the
     * worst kind of false assurance.
     *
     * There is deliberately no `ensureValidator()` on the port yet. A validator
     * is a `$jsonSchema` document plus a validation level and action — a shape
     * only MongoDB has — so the method belongs on an optional sub-interface
     * reached with `instanceof`, the way
     * {@see \AlfacodeTeam\PhpServicePlatform\Kernel\Ports\RangeReadableStorage}
     * extends {@see \AlfacodeTeam\PhpServicePlatform\Kernel\Ports\StoragePort}.
     * It will arrive with the first adapter that can honour it rather than be
     * guessed at now: an interface with no implementation has nothing to show
     * it is the right shape. This case exists meanwhile so that a status
     * command can report, truthfully, that nothing validates writes.
     */
    case Validator = 'validator';

    /**
     * The store deletes documents automatically once a timestamp field passes.
     *
     * MongoDB: a TTL index (`expireAfterSeconds`). Firestore: a TTL policy on a
     * field. Both exist, and both are best-effort rather than prompt — neither
     * promises deletion at the instant of expiry, so nothing may treat an
     * expired document as already gone.
     */
    case TtlIndex = 'ttl_index';

    /** A short human description for a status command. */
    public function label(): string
    {
        return match ($this) {
            self::UniqueIndex => 'unique indexes',
            self::Validator   => 'server-side schema validation',
            self::TtlIndex    => 'TTL expiry',
        };
    }
}
