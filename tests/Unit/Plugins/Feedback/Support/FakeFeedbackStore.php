<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Feedback\Support;

use Plugins\Feedback\API\DTOs\ListFeedbackQuery;
use Plugins\Feedback\Application\Ports\FeedbackStore;
use Plugins\Feedback\Domain\Entities\FeedbackEntry;

/**
 * In-memory FeedbackStore for service tests. Insertion order is newest-last;
 * paginate() returns newest-first to mirror the SQL ORDER BY id DESC.
 */
final class FakeFeedbackStore implements FeedbackStore
{
    /** @var array<string, FeedbackEntry> */
    private array $rows = [];

    public function insert(FeedbackEntry $entry): void
    {
        $this->rows[$entry->id()->value()] = $entry;
    }

    public function find(string $feedbackId): ?FeedbackEntry
    {
        return $this->rows[$feedbackId] ?? null;
    }

    public function paginate(ListFeedbackQuery $query): array
    {
        $all = array_reverse(array_values($this->rows));

        if ($query->status !== null) {
            $all = array_values(array_filter(
                $all,
                static fn(FeedbackEntry $e): bool => $e->status() === $query->status,
            ));
        }

        $page    = array_slice($all, 0, $query->limit);
        $hasMore = count($all) > $query->limit;

        return [$page, $hasMore];
    }

    public function updateStatus(string $feedbackId, string $status): bool
    {
        return isset($this->rows[$feedbackId]);
    }
}
