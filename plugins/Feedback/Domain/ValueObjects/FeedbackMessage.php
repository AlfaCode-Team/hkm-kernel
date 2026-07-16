<?php

declare(strict_types=1);

namespace Plugins\Feedback\Domain\ValueObjects;

/**
 * FeedbackMessage — the required free-text body (the `message` column).
 *
 * Trims, enforces a non-empty minimum and a hard upper bound so a single
 * submission can't be used to dump unbounded data. Control characters (except
 * newline/tab) are stripped as a defensive measure; the value is stored as-is
 * otherwise and ALWAYS escaped at the view layer (never trusted as HTML).
 */
final readonly class FeedbackMessage
{
    private const MIN = 1;
    private const MAX = 5000;

    private function __construct(private string $value)
    {
        $len = mb_strlen($this->value);
        if ($len < self::MIN) {
            throw new \DomainException('Feedback message cannot be empty.');
        }
        if ($len > self::MAX) {
            throw new \DomainException('Feedback message cannot exceed ' . self::MAX . ' characters.');
        }
    }

    public static function fromString(string $value): self
    {
        // Strip control chars except tab (\x09) and newline (\x0A).
        $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', trim($value)) ?? '';

        return new self($clean);
    }

    public function value(): string
    {
        return $this->value;
    }
}
