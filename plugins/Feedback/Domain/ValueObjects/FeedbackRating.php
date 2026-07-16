<?php

declare(strict_types=1);

namespace Plugins\Feedback\Domain\ValueObjects;

/**
 * FeedbackRating — an optional 1–5 star rating (the `rating` tinyint column).
 *
 * The range is enforced here so an out-of-bounds value can never reach the
 * database. Rating is optional, so use fromNullable() for absent input.
 */
final readonly class FeedbackRating
{
    private const MIN = 1;
    private const MAX = 5;

    private function __construct(private int $value)
    {
        if ($value < self::MIN || $value > self::MAX) {
            throw new \DomainException('Rating must be between 1 and 5.');
        }
    }

    public static function of(int $value): self
    {
        return new self($value);
    }

    /**
     * null/'' → no rating. Accepts an int, an integer-valued float (4.0) or a
     * digit string; rejects fractional floats, arrays and non-numeric strings.
     * Typed `mixed` because it receives raw request input (a JSON number may
     * decode as float) — a narrow union would TypeError under strict_types
     * instead of yielding a clean validation error.
     */
    public static function fromNullable(mixed $value): ?self
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value)) {
            return new self($value);
        }
        if (is_float($value) && floor($value) === $value) {
            return new self((int) $value);
        }
        if (is_string($value) && ctype_digit($value)) {
            return new self((int) $value);
        }

        throw new \DomainException('Rating must be a whole number 1–5.');
    }

    public function value(): int
    {
        return $this->value;
    }
}
