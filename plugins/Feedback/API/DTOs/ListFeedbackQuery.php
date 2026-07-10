<?php

declare(strict_types=1);

namespace Plugins\Feedback\API\DTOs;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use Plugins\Feedback\Domain\ValueObjects\FeedbackStatus;

/**
 * Keyset-pagination query for admin feedback triage.
 *
 *   GET /ajx/feedback?limit=50&status=received&after=<last feedback_id seen>
 *
 * `after` is the opaque public feedback_id of the last row from the previous
 * page; the repository resolves it to the internal sort key. `status` is an
 * optional triage filter, validated against the closed enum.
 */
final readonly class ListFeedbackQuery
{
    public const DEFAULT_LIMIT = 25;
    public const MAX_LIMIT     = 100;

    public function __construct(
        public int $limit,
        public ?string $after,
        public ?FeedbackStatus $status,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $limit = (int) $request->input('limit', self::DEFAULT_LIMIT);
        $limit = max(1, min($limit, self::MAX_LIMIT));

        $after = trim((string) $request->input('after', ''));
        // Cursor must look like a UUID; otherwise ignore it (start from the top).
        if ($after === '' || !preg_match('/^[0-9a-fA-F-]{36}$/', $after)) {
            $after = null;
        }

        // Unknown status → ignore the filter rather than 422 a read-only list.
        $status = FeedbackStatus::tryFrom(trim((string) $request->input('status', '')));

        return new self(limit: $limit, after: $after, status: $status);
    }
}
