<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Document;

/**
 * An index definition — what a "migration" means for a document store.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * WHY INDEXES ARE THE MIGRATION, AND TABLES ARE NOT
 * ────────────────────────────────────────────────────────────────────────────
 * A relational migration creates the shape of the data: without the table and
 * its columns there is nowhere to put anything. Neither of these stores works
 * that way. Both create a collection implicitly on first write, and both accept
 * any shape of document, so there is nothing to declare in advance for a write
 * to succeed.
 *
 * What is NOT implicit is indexes, and on one of the two stores a missing index
 * is not a slow query but a failed one. Firestore "doesn't automatically create
 * indexes for combinations of fields", and a query needing one it does not have
 * is answered with an error rather than a scan. So the index IS the schema
 * object that has to exist before the code that depends on it — which is
 * exactly the definition of a migration.
 *
 * The other half is constraints: a unique index, a validator, a TTL policy. Those
 * are not performance tuning either; they are invariants, and they are what
 * {@see DocumentStoreCapability} is about.
 */
final class DocumentIndex
{
    /**
     * MongoDB: "There can be no more than 32 fields in a compound index."
     *
     * Measured against MongoDB 9.0.4 rather than taken on trust: 32 fields
     * creates, 33 fails with `too many compound keys` (code 13103).
     *
     * Firestore's own ceiling is 100 fields, so this is MongoDB's limit taken as
     * the port's. Taking the lower of two bounds is the whole method here: a
     * definition that is legal on one store and rejected by the other is a
     * definition this port should never have accepted.
     */
    public const MAX_FIELDS = 32;

    /**
     * @param array<string, SortDirection> $fields     field path => direction, in index order
     * @param non-empty-string             $name
     */
    private function __construct(
        public readonly string $collection,
        public readonly string $name,
        public readonly array $fields,
        public readonly bool $unique,
        public readonly ?int $ttlSeconds,
    ) {}

    /**
     * An ordinary index over one or more fields.
     *
     * FIELD ORDER IS SIGNIFICANT and is not a detail either store will correct.
     * A compound index on (a, b) serves a query filtering on `a`, or on `a` and
     * `b`; it does not serve one filtering on `b` alone. Both stores behave this
     * way, so the order written here is the order queries must use.
     *
     * @param array<string, SortDirection>|list<string> $fields a path => direction
     *                                                          map, or a plain
     *                                                          list for all-ascending
     */
    public static function of(string $collection, array $fields, ?string $name = null): self
    {
        CollectionName::assertValid($collection);
        $normalised = self::normalise($fields);

        return new self(
            $collection,
            self::resolveName($collection, $normalised, $name),
            $normalised,
            false,
            null,
        );
    }

    /**
     * An index that also REFUSES duplicate values.
     *
     * Requires {@see DocumentStoreCapability::UniqueIndex}. An adapter that has
     * not declared it throws rather than creating a plain index, because a plain
     * index where a unique one was asked for is an invariant silently removed —
     * the application goes on believing duplicates are impossible while every
     * duplicate write succeeds.
     *
     * @param array<string, SortDirection>|list<string> $fields
     */
    public static function unique(string $collection, array $fields, ?string $name = null): self
    {
        CollectionName::assertValid($collection);
        $normalised = self::normalise($fields);

        return new self(
            $collection,
            self::resolveName($collection, $normalised, $name),
            $normalised,
            true,
            null,
        );
    }

    /**
     * An index whose documents are deleted once $ttlSeconds have passed since
     * the value in the single indexed field.
     *
     * Requires {@see DocumentStoreCapability::TtlIndex}. Expiry is best-effort
     * on both stores: neither promises deletion at the moment of expiry, so a
     * read MUST still check the timestamp rather than assume an expired document
     * is gone. Using TTL as a security boundary — "the token row disappears, so
     * the token stops working" — is therefore wrong; check the expiry in code
     * and let TTL reclaim the space.
     *
     * One field only. Both stores expire on a single date field, and a compound
     * TTL index is not a thing either of them has.
     */
    public static function ttl(string $collection, string $field, int $ttlSeconds, ?string $name = null): self
    {
        if ($ttlSeconds < 0) {
            throw new \InvalidArgumentException(\sprintf(
                'A TTL must not be negative; %d was given for "%s".',
                $ttlSeconds,
                $field,
            ));
        }

        CollectionName::assertValid($collection);
        $fields = self::normalise([$field]);

        return new self(
            $collection,
            self::resolveName($collection, $fields, $name),
            $fields,
            false,
            $ttlSeconds,
        );
    }

    /**
     * The capabilities an adapter must have to create this index.
     *
     * @return list<DocumentStoreCapability>
     */
    public function requiredCapabilities(): array
    {
        $required = [];

        if ($this->unique) {
            $required[] = DocumentStoreCapability::UniqueIndex;
        }

        if ($this->ttlSeconds !== null) {
            $required[] = DocumentStoreCapability::TtlIndex;
        }

        return $required;
    }

    /** @return list<string> the indexed field paths, in index order */
    public function fieldNames(): array
    {
        return array_keys($this->fields);
    }

    /** A one-line description for a migration log. */
    public function describe(): string
    {
        $parts = [];

        foreach ($this->fields as $field => $direction) {
            $parts[] = $field . ':' . $direction->value;
        }

        $suffix = $this->unique ? ' unique' : '';

        if ($this->ttlSeconds !== null) {
            $suffix .= ' ttl=' . $this->ttlSeconds . 's';
        }

        return $this->collection . '(' . implode(', ', $parts) . ')' . $suffix;
    }

    /**
     * @param  array<string, SortDirection>|list<string> $fields
     * @return array<string, SortDirection>
     */
    private static function normalise(array $fields): array
    {
        if ($fields === []) {
            throw new \InvalidArgumentException(
                'An index must cover at least one field.',
            );
        }

        $normalised = [];

        foreach ($fields as $key => $value) {
            if (\is_int($key)) {
                // A plain list: ['a', 'b'] means both ascending.
                if (!\is_string($value)) {
                    throw new \InvalidArgumentException(\sprintf(
                        'An index field list may only contain strings; %s was given.',
                        get_debug_type($value),
                    ));
                }

                $field     = $value;
                $direction = SortDirection::Asc;
            } else {
                if (!$value instanceof SortDirection) {
                    throw new \InvalidArgumentException(\sprintf(
                        'An index field map must map a path to a SortDirection; "%s" maps to %s.',
                        $key,
                        get_debug_type($value),
                    ));
                }

                $field     = $key;
                $direction = $value;
            }

            FieldPath::assertValid($field, 'index field');

            if (isset($normalised[$field])) {
                throw new \InvalidArgumentException(\sprintf(
                    'An index may not list "%s" twice.',
                    $field,
                ));
            }

            $normalised[$field] = $direction;
        }

        if (\count($normalised) > self::MAX_FIELDS) {
            throw new \InvalidArgumentException(\sprintf(
                'An index may cover at most %d fields; %d were given. That is MongoDB\'s documented '
                . 'ceiling on a compound index, and it is lower than Firestore\'s.',
                self::MAX_FIELDS,
                \count($normalised),
            ));
        }

        return $normalised;
    }

    /**
     * Derive a stable name when none was given.
     *
     * ────────────────────────────────────────────────────────────────────────
     * THE NAME IS NOT COSMETIC — IT DECIDES WHETHER A RE-RUN IS AN ERROR
     * ────────────────────────────────────────────────────────────────────────
     * MongoDB raises `IndexOptionsConflict` (code 85) when the same key pattern
     * is created under a second name — measured on 9.0.4: "Index already exists
     * with a different name: a_asc". So a migration that lets the name drift
     * between runs does not create a second index; it fails.
     *
     * A derived name is therefore a pure function of the collection and the
     * fields, and it must stay one. Including the direction matters for the same
     * reason: (a asc) and (a desc) are different indexes, and a name that
     * collapsed them would make one of the two permanently unachievable.
     */
    /**
     * @param  non-empty-string             $collection
     * @param  array<string, SortDirection> $fields
     * @return non-empty-string
     */
    private static function resolveName(string $collection, array $fields, ?string $name): string
    {
        if ($name !== null) {
            if ($name === '') {
                throw new \InvalidArgumentException(
                    'An index name may not be an empty string. Pass null to derive one.',
                );
            }

            if (preg_match('/^[A-Za-z0-9_.-]+$/', $name) !== 1) {
                throw new \InvalidArgumentException(\sprintf(
                    'An index name may contain only letters, digits, underscore, dot and hyphen: "%s".',
                    $name,
                ));
            }

            return $name;
        }

        $parts = [$collection];

        foreach ($fields as $field => $direction) {
            $parts[] = str_replace('.', '_', $field) . '_' . ($direction->isDescending() ? 'desc' : 'asc');
        }

        $derived = implode('__', $parts);

        // MongoDB's limit is on the full namespace rather than the index name
        // alone, so a long derived name is hashed rather than truncated:
        // truncation would let two different definitions derive ONE name, which
        // is the conflict above, reached by accident.
        return \strlen($derived) > 100
            ? 'idx_' . substr(hash('sha256', $derived), 0, 32)
            : $derived;
    }
}
