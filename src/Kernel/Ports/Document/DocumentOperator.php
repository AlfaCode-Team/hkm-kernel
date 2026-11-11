<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Document;

/**
 * The comparison operators a {@see DocumentFilter} may use.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * WHY THIS LIST IS SHORT, AND WHY IT IS EXACTLY THIS SHORT
 * ────────────────────────────────────────────────────────────────────────────
 * These are the ten operators Firestore's `FieldFilter` defines, and every one
 * of them has a direct MongoDB equivalent. That is not a coincidence — it is the
 * whole selection rule. The intersection of the two query languages IS this
 * list, so a port offering more could not be honoured by both adapters.
 *
 * MongoDB's query language is far richer: `$regex`, `$elemMatch`, `$where`,
 * `$expr`, `$text`, the aggregation pipeline. Firestore has none of them. A port
 * that accepted a Mongo-shaped filter array would therefore compile fine, pass
 * every test against the Mongo adapter, and throw on Firestore the first time a
 * second backend was configured — which in practice means in production, since
 * nobody develops against the backend they are not using.
 *
 * So the port refuses the richer grammar rather than offering it unevenly. A
 * capability only one store has belongs behind that store's own adapter-specific
 * interface, reached with an `instanceof` check, exactly as
 * {@see \AlfacodeTeam\PhpServicePlatform\Kernel\Ports\RangeReadableStorage}
 * extends {@see \AlfacodeTeam\PhpServicePlatform\Kernel\Ports\StoragePort}.
 *
 * Reference, both verified against the published contracts:
 *   - Firestore `FieldFilter.Operator`: LESS_THAN, LESS_THAN_OR_EQUAL,
 *     GREATER_THAN, GREATER_THAN_OR_EQUAL, EQUAL, NOT_EQUAL, ARRAY_CONTAINS,
 *     IN, ARRAY_CONTAINS_ANY, NOT_IN.
 *   - MongoDB: $lt, $lte, $gt, $gte, $eq, $ne, (array equality match), $in,
 *     $in over an array field, $nin.
 */
enum DocumentOperator: string
{
    case Eq  = 'eq';
    case Ne  = 'ne';
    case Lt  = 'lt';
    case Lte = 'lte';
    case Gt  = 'gt';
    case Gte = 'gte';

    /** Field equals at least one of the given values. Firestore caps at 30. */
    case In = 'in';

    /** Field equals none of the given values. Firestore caps at 10, not 30. */
    case NotIn = 'not_in';

    /** Field is an array that contains the given value. */
    case ArrayContains = 'array_contains';

    /** Field is an array containing any of the given values. Firestore caps at 30. */
    case ArrayContainsAny = 'array_contains_any';

    /**
     * Whether this operator is a RANGE comparison.
     *
     * Load-bearing, not cosmetic: Firestore requires that when a query carries a
     * range filter, the first ordering be on a range-filtered field. {@see
     * DocumentQuery} enforces that, and this is how it knows.
     */
    public function isRange(): bool
    {
        return match ($this) {
            self::Lt, self::Lte, self::Gt, self::Gte => true,
            default                                  => false,
        };
    }

    /** Whether this operator takes a LIST of values rather than a single one. */
    public function takesList(): bool
    {
        return match ($this) {
            self::In, self::NotIn, self::ArrayContainsAny => true,
            default                                      => false,
        };
    }

    /**
     * The most values Firestore accepts for this operator, or 0 when it takes
     * a single value.
     *
     * `In` and `ArrayContainsAny` allow 30; `NotIn` allows only 10. The
     * asymmetry is Firestore's, and it is the kind of detail that is discovered
     * from a production error rather than remembered, so the port checks it.
     */
    public function maxValues(): int
    {
        return match ($this) {
            self::In, self::ArrayContainsAny => 30,
            self::NotIn                      => 10,
            default                          => 0,
        };
    }

    /**
     * Whether this operator SILENTLY EXCLUDES documents that lack the field.
     *
     * ────────────────────────────────────────────────────────────────────────
     * THE DIVERGENCE THAT MAKES THIS METHOD NECESSARY
     * ────────────────────────────────────────────────────────────────────────
     * Firestore: "`!=` and `not-in` exclude documents where the field doesn't
     * exist", and "Documents with a `null` value also don't match `!=`".
     *
     * MongoDB does the opposite on both counts. `{status: {$ne: 'paid'}}`
     * matches a document with no `status` field at all, and matches one where
     * `status` is null — because in Mongo a missing field compares as null, and
     * null is not equal to 'paid', so the predicate is true.
     *
     * The same filter over the same data therefore returns DIFFERENT DOCUMENTS
     * on the two backends, with no error on either. An adapter MUST converge on
     * the Firestore reading — see {@see \AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DocumentStorePort}
     * for the mandate and the reason that direction was chosen.
     */
    public function excludesMissingField(): bool
    {
        return $this === self::Ne || $this === self::NotIn;
    }
}
