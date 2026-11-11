<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Vector;

/**
 * One condition on a payload field. All conditions in a query are AND-ed.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * THE SEMANTICS, WHICH {@see self::matches()} EXECUTES
 * ────────────────────────────────────────────────────────────────────────────
 * 1. A MISSING FIELD NEVER MATCHES. Every operator. There is no way to ask for
 *    "no such field", on purpose.
 *
 * 2. TYPES ARE STRICT. The string "5" does not equal the integer 5, and `true`
 *    does not equal 1. Measured on PostgreSQL 18.6, jsonb containment says
 *    `{"n":"5"} @> {"n":5}` is false and `{"n":true} @> {"n":1}` is false, while
 *    `{"n":5} @> {"n":5.0}` is TRUE — integers and floats are one numeric type.
 *    So Eq on an integer matches a stored 5.0, and nothing else.
 *
 * 3. RANGES ARE NUMERIC ONLY, and a non-number never satisfies one. This is not
 *    a convenience; it is the one place a naive adapter breaks, in two opposite
 *    ways, both measured:
 *      • `(payload->>'k')::numeric >= 5` THROWS for the whole query the moment
 *        any row has a non-numeric value in `k` ("invalid input syntax for type
 *        numeric: "t1"") — one bad row takes the search down.
 *      • `payload->'k' >= '5'::jsonb`, comparing jsonb to jsonb, never throws but
 *        orders values by TYPE first (Object > Array > Boolean > Number > String
 *        > Null): `'true'::jsonb > '5'::jsonb` is TRUE. A boolean — or an array,
 *        or an object — satisfies "greater than 5".
 *    The adapter must guard on the stored type before comparing.
 *    String ranges are refused outright: they depend on a collation that
 *    differs between stores, and not every store can run them.
 *
 * 4. Floats are refused for Eq and In. Equality on a float is a precision lottery
 *    — pgvector stores vectors as float32, and a payload that round-trips through
 *    JSON may not compare equal to the literal it came from.
 *
 * Field names are `[A-Za-z_][A-Za-z0-9_]{0,63}`: flat, no dots. A dotted name
 * would mean "nested field" on one store and "a key containing a dot" on another.
 */
final readonly class VectorFilter
{
    public const MAX_IN_VALUES = 100;

    /** @param bool|int|float|string|list<int|string> $value */
    private function __construct(
        public string $field,
        public VectorFilterOperator $operator,
        public bool|int|float|string|array $value,
    ) {}

    public static function eq(string $field, string|int|bool $value): self
    {
        self::assertField($field);

        return new self($field, VectorFilterOperator::Eq, $value);
    }

    /** @param list<int|string> $values */
    public static function in(string $field, array $values): self
    {
        self::assertField($field);

        if ($values === [] || !array_is_list($values)) {
            throw new \InvalidArgumentException(
                'An In filter needs a non-empty list. An empty list would mean "match nothing", which '
                . 'stores disagree about how to spell, and is almost always a bug upstream.',
            );
        }

        if (\count($values) > self::MAX_IN_VALUES) {
            throw new \InvalidArgumentException(\sprintf(
                'An In filter takes at most %d values; %d were given.',
                self::MAX_IN_VALUES,
                \count($values),
            ));
        }

        foreach ($values as $v) {
            if (!\is_int($v) && !\is_string($v)) {
                throw new \InvalidArgumentException(\sprintf(
                    'An In filter takes strings and integers only; got %s. Floats cannot be compared '
                    . 'for equality, and a boolean In is just Eq.',
                    get_debug_type($v),
                ));
            }
        }

        return new self($field, VectorFilterOperator::In, $values);
    }

    public static function gt(string $field, int|float $value): self
    {
        return self::range($field, VectorFilterOperator::Gt, $value);
    }

    public static function gte(string $field, int|float $value): self
    {
        return self::range($field, VectorFilterOperator::Gte, $value);
    }

    public static function lt(string $field, int|float $value): self
    {
        return self::range($field, VectorFilterOperator::Lt, $value);
    }

    public static function lte(string $field, int|float $value): self
    {
        return self::range($field, VectorFilterOperator::Lte, $value);
    }

    /** @throws \InvalidArgumentException */
    public static function assertField(string $field): void
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/D', $field) !== 1) {
            throw new \InvalidArgumentException(\sprintf(
                'A payload field name must match [A-Za-z_][A-Za-z0-9_]{0,63}: "%s" does not. Dotted '
                . 'names are refused because stores disagree on whether a dot means nesting.',
                substr(preg_replace('/[^\x20-\x7E]/', '?', $field) ?? '', 0, 64),
            ));
        }
    }

    /**
     * Whether a payload satisfies this condition, under the rules above.
     *
     * This is the SPECIFICATION as executable code. An in-memory adapter can use
     * it directly, and an SQL adapter's tests compare its answers against it —
     * so the expected result never comes from the SQL under test.
     *
     * @param array<string, bool|int|float|string> $payload
     */
    public function matches(array $payload): bool
    {
        if (!\array_key_exists($this->field, $payload)) {
            return false;
        }

        $actual = $payload[$this->field];

        return match ($this->operator) {
            VectorFilterOperator::Eq  => self::same($actual, $this->value),
            VectorFilterOperator::In  => array_any(
                (array) $this->value,
                static fn (mixed $candidate): bool => self::same($actual, $candidate),
            ),
            VectorFilterOperator::Gt  => self::isNumber($actual) && $actual >  $this->value,
            VectorFilterOperator::Gte => self::isNumber($actual) && $actual >= $this->value,
            VectorFilterOperator::Lt  => self::isNumber($actual) && $actual <  $this->value,
            VectorFilterOperator::Lte => self::isNumber($actual) && $actual <= $this->value,
        };
    }

    private static function range(string $field, VectorFilterOperator $op, int|float $value): self
    {
        self::assertField($field);

        if (\is_float($value) && (is_nan($value) || is_infinite($value))) {
            throw new \InvalidArgumentException('A range bound must be finite.');
        }

        return new self($field, $op, $value);
    }

    private static function isNumber(mixed $v): bool
    {
        return \is_int($v) || \is_float($v);
    }

    /** Strict on type, except that int and float are one numeric type. */
    private static function same(mixed $a, mixed $b): bool
    {
        if (self::isNumber($a) && self::isNumber($b)) {
            return $a == $b;
        }

        return $a === $b;
    }
}
