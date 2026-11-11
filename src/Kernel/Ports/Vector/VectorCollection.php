<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Vector;

/**
 * The definition of a collection: a name, a fixed dimension and a metric.
 *
 * Immutable and compared by value, because {@see \AlfacodeTeam\PhpServicePlatform\Kernel\Ports\VectorStorePort::ensureCollection()}
 * answers "does the store already hold exactly this?" and the answer must not
 * depend on object identity.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * NAMES
 * ────────────────────────────────────────────────────────────────────────────
 * `[a-z][a-z0-9_]{0,47}`. A conservative intersection, not a documented limit of
 * any one store: lower-case with no hyphen or dot means the name is a legal bare
 * SQL identifier (an adapter over PostgreSQL derives a table name from it and
 * never has to quote user input), a legal Qdrant or Pinecone-style name, and
 * case-insensitive stores cannot see two names collide. 48 leaves room for an
 * adapter's own prefix inside PostgreSQL's 63-byte identifier limit.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * WHAT {@see self::assertAccepts()} REFUSES
 * ────────────────────────────────────────────────────────────────────────────
 * A different length (the model changed), and — for cosine only — the zero
 * vector. Measured on pgvector 0.8.7, `[0,0,0] <=> [1,0,0]` is `NaN`, and a zero
 * QUERY vector makes the distance NaN for every row, so the "nearest" results
 * come back in an arbitrary order with nothing to say so. NaN also sorts AFTER
 * every real distance, so a stored zero vector is not an error — it is silently
 * the farthest thing from every query, for ever. An embedding model returning
 * all zeros means something upstream failed (an empty input it did not refuse),
 * and the store is the wrong place to find out.
 */
final readonly class VectorCollection
{
    public const MAX_NAME_LENGTH = 48;

    public function __construct(
        public string $name,
        public int $dimensions,
        public VectorMetric $metric = VectorMetric::Cosine,
    ) {
        self::assertValidName($name);

        if ($dimensions < 1 || $dimensions > Vector::MAX_DIMENSIONS) {
            throw new \InvalidArgumentException(\sprintf(
                'A collection holds between 1 and %d dimensions; %d was requested.',
                Vector::MAX_DIMENSIONS,
                $dimensions,
            ));
        }
    }

    /** @throws \InvalidArgumentException */
    public static function assertValidName(string $name): void
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,47}$/D', $name) !== 1) {
            throw new \InvalidArgumentException(\sprintf(
                'A vector collection name must match [a-z][a-z0-9_]{0,%d}: %s',
                self::MAX_NAME_LENGTH - 1,
                $name === '' ? 'it was empty.' : '"' . self::printable($name) . '" does not.',
            ));
        }
    }

    /**
     * @throws DimensionMismatchException
     * @throws \InvalidArgumentException  a zero vector in a cosine collection
     */
    public function assertAccepts(Vector $vector): void
    {
        if ($vector->dimensions() !== $this->dimensions) {
            throw new DimensionMismatchException($this->name, $this->dimensions, $vector->dimensions());
        }

        if ($this->metric === VectorMetric::Cosine && $vector->isZero()) {
            throw new \InvalidArgumentException(\sprintf(
                'Collection "%s" uses cosine distance, which is undefined for the zero vector '
                . '(pgvector returns NaN, and NaN sorts after every real distance). An embedding '
                . 'model that returns all zeros has failed upstream; refuse it there.',
                $this->name,
            ));
        }
    }

    public function equals(self $other): bool
    {
        return $this->name === $other->name
            && $this->dimensions === $other->dimensions
            && $this->metric === $other->metric;
    }

    private static function printable(string $s): string
    {
        return substr(preg_replace('/[^\x20-\x7E]/', '?', $s) ?? '', 0, 64);
    }
}
