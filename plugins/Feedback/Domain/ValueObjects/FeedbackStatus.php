<?php

declare(strict_types=1);

namespace Plugins\Feedback\Domain\ValueObjects;

/**
 * FeedbackStatus — the triage lifecycle (`status` column).
 *
 * received → acknowledged → resolved. Transitions are forward-only and validated
 * by canTransitionTo() so the entity can reject an illegal jump.
 */
enum FeedbackStatus: string
{
    case Received     = 'received';
    case Acknowledged = 'acknowledged';
    case Resolved     = 'resolved';

    public static function fromString(string $value): self
    {
        return self::tryFrom(trim($value))
            ?? throw new \DomainException('Unknown feedback status.');
    }

    /** Forward-only: a status may advance, never regress. */
    public function canTransitionTo(self $next): bool
    {
        return $next->rank() > $this->rank();
    }

    private function rank(): int
    {
        return match ($this) {
            self::Received     => 0,
            self::Acknowledged => 1,
            self::Resolved     => 2,
        };
    }
}
