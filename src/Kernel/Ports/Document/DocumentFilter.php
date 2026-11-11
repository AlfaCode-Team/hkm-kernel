<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Document;

/**
 * One `field OPERATOR value` predicate in a {@see DocumentQuery}.
 *
 * Filters combine with AND, and ONLY with AND. There is deliberately no `or()`:
 * Firestore serves a disjunction from a composite index and caps a query at 30
 * disjunctions in disjunctive normal form, with at most one `array-contains`
 * per `or` group — a set of rules an adapter would have to enforce by analysing
 * the caller's boolean tree, and whose violation surfaces as a production error
 * rather than a refusal here. Two AND-queries and a merge in PHP is the honest
 * way to express a union across this port.
 *
 * Every constraint below is checked HERE, at construction, rather than by the
 * adapter at query time. That is the point: a filter that only one store can run
 * must fail on the developer's machine, under whichever adapter is configured,
 * and not on whichever backend happens to be in production.
 */
final class DocumentFilter
{
    /**
     * @param list<mixed>|null $values for a list operator; null otherwise
     */
    private function __construct(
        public readonly string $field,
        public readonly DocumentOperator $operator,
        public readonly mixed $value,
        public readonly ?array $values,
    ) {}

    /** `$field == $value` */
    public static function eq(string $field, mixed $value): self
    {
        return self::single($field, DocumentOperator::Eq, $value);
    }

    /**
     * `$field != $value`.
     *
     * Read {@see DocumentOperator::excludesMissingField()} before using this:
     * a document with no such field, and a document whose value is null, are
     * BOTH excluded. That is Firestore's rule, and the port adopts it on every
     * store so the predicate means one thing. If "or the field is absent" is
     * what you want, this operator cannot express it on Firestore at all — run
     * the positive query and subtract.
     */
    public static function ne(string $field, mixed $value): self
    {
        return self::single($field, DocumentOperator::Ne, $value);
    }

    public static function lt(string $field, mixed $value): self
    {
        return self::single($field, DocumentOperator::Lt, $value);
    }

    public static function lte(string $field, mixed $value): self
    {
        return self::single($field, DocumentOperator::Lte, $value);
    }

    public static function gt(string $field, mixed $value): self
    {
        return self::single($field, DocumentOperator::Gt, $value);
    }

    public static function gte(string $field, mixed $value): self
    {
        return self::single($field, DocumentOperator::Gte, $value);
    }

    /**
     * `$field` is an array containing `$value`.
     *
     * At most ONE of these per query — Firestore allows one `array-contains`
     * per disjunction, and a query with no disjunctions has exactly one.
     */
    public static function arrayContains(string $field, mixed $value): self
    {
        return self::single($field, DocumentOperator::ArrayContains, $value);
    }

    /**
     * `$field` equals one of `$values`. At most 30 values.
     *
     * @param list<mixed> $values
     */
    public static function in(string $field, array $values): self
    {
        return self::list($field, DocumentOperator::In, $values);
    }

    /**
     * `$field` equals none of `$values`. At most 10 values — not 30.
     *
     * @param list<mixed> $values
     */
    public static function notIn(string $field, array $values): self
    {
        return self::list($field, DocumentOperator::NotIn, $values);
    }

    /**
     * `$field` is an array containing any of `$values`. At most 30 values.
     *
     * @param list<mixed> $values
     */
    public static function arrayContainsAny(string $field, array $values): self
    {
        return self::list($field, DocumentOperator::ArrayContainsAny, $values);
    }

    /** Whether this predicate is a range comparison. */
    public function isRange(): bool
    {
        return $this->operator->isRange();
    }

    /** A short description for an exception message or a log. Never the value. */
    public function describe(): string
    {
        return $this->field . ' ' . $this->operator->value
            . ($this->values !== null ? ' [' . \count($this->values) . ' values]' : '');
    }

    private static function single(string $field, DocumentOperator $operator, mixed $value): self
    {
        FieldPath::assertValid($field);
        self::assertComparable($field, $operator, $value);

        return new self($field, $operator, $value, null);
    }

    /** @param list<mixed> $values */
    private static function list(string $field, DocumentOperator $operator, array $values): self
    {
        FieldPath::assertValid($field);

        if ($values === []) {
            // Firestore answers an empty `in` with no documents, and MongoDB's
            // `$in: []` does the same, so both "work" — which is exactly why
            // this is refused. An empty list is almost always an unfiltered
            // collection that arrived empty, and silently returning nothing
            // reads as "no results" rather than as the bug it is.
            throw new \InvalidArgumentException(\sprintf(
                'DocumentFilter::%s("%s", []) was given no values. Both stores answer an empty list '
                . 'with zero documents, which is indistinguishable from a legitimate empty result — '
                . 'so guard the empty case at the call site and skip the query.',
                $operator->value,
                $field,
            ));
        }

        $max = $operator->maxValues();

        if (\count($values) > $max) {
            throw new \InvalidArgumentException(\sprintf(
                '%s accepts at most %d values on Firestore; %d were given for "%s". '
                . 'Split the query and merge the results, or filter in PHP.',
                $operator->value,
                $max,
                \count($values),
                $field,
            ));
        }

        foreach ($values as $value) {
            // Firestore: "A `not-in` query with `null` as a comparison value
            // does not match any documents." A null inside `in` is no better —
            // it matches only explicit nulls there and nothing at all here,
            // while MongoDB would also match missing fields. Both readings are
            // surprising, so neither is offered.
            if ($value === null) {
                throw new \InvalidArgumentException(\sprintf(
                    'A null inside %s("%s", ...) is not portable: on Firestore a not-in containing '
                    . 'null matches NO documents at all, while MongoDB would match every document '
                    . 'missing the field. Test for null with a separate query.',
                    $operator->value,
                    $field,
                ));
            }

            self::assertComparable($field, $operator, $value);
        }

        return new self($field, $operator, null, array_values($values));
    }

    /**
     * Refuse a value neither store can compare.
     *
     * Both stores compare scalars, nulls, dates and (for the array operators)
     * array members. Neither can compare an object or a resource, and a closure
     * passed here is a bug that would otherwise surface as a serialisation
     * error deep inside an adapter.
     */
    private static function assertComparable(string $field, DocumentOperator $operator, mixed $value): void
    {
        if ($value === null && $operator->isRange()) {
            throw new \InvalidArgumentException(\sprintf(
                'A range comparison against null is not meaningful: %s %s null. MongoDB orders null '
                . 'below every number and Firestore orders it below every type, so the two disagree '
                . 'about what this matches.',
                $field,
                $operator->value,
            ));
        }

        if (\is_scalar($value) || $value === null || $value instanceof \DateTimeInterface) {
            return;
        }

        if (\is_array($value) && $operator === DocumentOperator::Eq) {
            // An exact array match is expressible on both stores.
            return;
        }

        throw new \InvalidArgumentException(\sprintf(
            'A %s cannot be compared: %s %s. A filter value must be a scalar, null, a '
            . 'DateTimeInterface, or (for an equality match) an array.',
            get_debug_type($value),
            $field,
            $operator->value,
        ));
    }
}
