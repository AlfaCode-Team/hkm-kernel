<?php

declare(strict_types=1);

namespace Plugins\User\Domain\ValueObjects;

/**
 * FeedbackCategory — the optional `category` column (whitelist).
 *
 * A closed enum is the validation: anything outside these cases is rejected at
 * the boundary, so the column can never hold an arbitrary client-supplied
 * string. Category is optional, so use fromNullable() for absent input.
 */
enum FeedbackCategory: string
{
    case SearchBrowsing  = 'search_browsing';
    case Messaging       = 'messaging';
    case Payments        = 'payments';
    case Hosting         = 'hosting';
    case AppPerformance  = 'app_performance';
    case FeatureRequest  = 'feature_request';
    case Other           = 'other';

    /**
     * Parse optional input. null/'' → null (no category). An unknown non-empty
     * value throws so the caller surfaces a 422 instead of silently dropping it.
     */
    public static function fromNullable(?string $value): ?self
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        return self::tryFrom($value)
            ?? throw new \DomainException('Unknown feedback category.');
    }
}
