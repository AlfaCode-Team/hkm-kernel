<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Vector;

/**
 * One embedding: a non-empty list of finite floats.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * WHAT IS REFUSED, AND WHY EACH ONE IS REFUSED HERE
 * ────────────────────────────────────────────────────────────────────────────
 * NaN and infinity. Measured on pgvector 0.8.7, the store itself rejects them
 * ("NaN not allowed in vector", "infinite value not allowed in vector"), and an
 * adapter for a store that did NOT would index a vector whose every distance is
 * NaN. Refusing at construction gives every backend the stricter reading.
 *
 * An empty vector (`vector must have at least 1 dimension`, measured), and
 * anything above {@see self::MAX_DIMENSIONS}.
 *
 * Why 2000: pgvector refuses to build an HNSW index on a `vector` column of more
 * than 2000 dimensions ("column cannot have more than 2000 dimensions for hnsw
 * index", measured at 2001 and 3000) even though the column type itself goes to
 * 16000. A collection that can be stored but never indexed is a sequential scan
 * waiting to happen, so the port takes the tighter number. Models that emit more
 * (3072-dimension embeddings, for example) must be asked for fewer dimensions
 * or truncated by the caller; the port does not silently do either.
 *
 * Values are normalised to `float`. pgvector stores float32, so a vector read
 * back differs from the one written in about the eighth significant digit
 * (measured: 0.123456789012 comes back as 0.12345679). Never compare a stored
 * vector to a computed one with `===`.
 */
final readonly class Vector
{
    public const MAX_DIMENSIONS = 2000;

    /** @param list<float> $values */
    private function __construct(private array $values) {}

    /**
     * @param array<int|string, mixed> $values
     * @throws \InvalidArgumentException
     */
    public static function of(array $values): self
    {
        if ($values === []) {
            throw new \InvalidArgumentException('A vector needs at least one dimension.');
        }

        if (!array_is_list($values)) {
            throw new \InvalidArgumentException(
                'A vector must be a list: its position is its dimension, so string or gapped keys '
                . 'would silently reorder it.',
            );
        }

        if (\count($values) > self::MAX_DIMENSIONS) {
            throw new \InvalidArgumentException(\sprintf(
                'A vector may have at most %d dimensions; this one has %d. pgvector cannot build an '
                . 'HNSW index above %1$d, and a collection that cannot be indexed is searched by a '
                . 'full scan.',
                self::MAX_DIMENSIONS,
                \count($values),
            ));
        }

        $floats = [];

        foreach ($values as $i => $value) {
            // bool is refused explicitly: `true` is not a coordinate, and
            // is_numeric()/(float) would happily turn it into 1.0.
            if (!\is_int($value) && !\is_float($value)) {
                throw new \InvalidArgumentException(\sprintf(
                    'Vector dimension %d is %s, not a number. Numeric strings are refused as well: '
                    . 'a "0,5" that reached here has already been mis-parsed upstream.',
                    $i,
                    get_debug_type($value),
                ));
            }

            $value = (float) $value;

            if (is_nan($value) || is_infinite($value)) {
                throw new \InvalidArgumentException(\sprintf(
                    'Vector dimension %d is not finite. No store indexes NaN or infinity, and one '
                    . 'such value makes every distance involving the vector undefined.',
                    $i,
                ));
            }

            $floats[] = $value;
        }

        return new self($floats);
    }

    public function dimensions(): int
    {
        return \count($this->values);
    }

    /** @return list<float> */
    public function toArray(): array
    {
        return $this->values;
    }

    /** True when every coordinate is zero — the one vector cosine distance is undefined for. */
    public function isZero(): bool
    {
        foreach ($this->values as $v) {
            if ($v !== 0.0) {
                return false;
            }
        }

        return true;
    }
}
