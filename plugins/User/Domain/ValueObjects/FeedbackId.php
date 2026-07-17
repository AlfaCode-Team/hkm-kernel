<?php

declare(strict_types=1);

namespace Plugins\User\Domain\ValueObjects;

/**
 * FeedbackId — the PUBLIC opaque identifier (the `feedback_id` char(36) column).
 *
 * A random UUID v4: it carries no row-count information, so it is safe to hand
 * to clients. The table's auto-increment `id` stays internal and never leaves
 * the persistence layer.
 */
final readonly class FeedbackId
{
    private const PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    private function __construct(private string $value)
    {
        if (!preg_match(self::PATTERN, $value)) {
            throw new \DomainException('FeedbackId must be a valid UUID v4.');
        }
    }

    public static function generate(): self
    {
        $data    = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40); // version 4
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80); // variant 10xx

        return new self(vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4)));
    }

    public static function fromString(string $value): self
    {
        return new self(strtolower(trim($value)));
    }

    public function value(): string
    {
        return $this->value;
    }
}
