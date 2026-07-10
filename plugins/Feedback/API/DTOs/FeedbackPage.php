<?php

declare(strict_types=1);

namespace Plugins\User\API\DTOs;

use Plugins\User\Domain\Entities\FeedbackEntry;

/**
 * A single keyset page of feedback entries plus the cursor for the next page.
 */
final readonly class FeedbackPage
{
    /** @param list<FeedbackEntry> $items */
    public function __construct(
        public array $items,
        public bool $hasMore,
        public int $limit,
    ) {}

    /** The cursor to pass as ?after= for the next page (null on the last page). */
    public function nextCursor(): ?string
    {
        if (!$this->hasMore || $this->items === []) {
            return null;
        }
        return $this->items[array_key_last($this->items)]->id()->value();
    }

    /** @return array<string,mixed> */
    public function meta(): array
    {
        return [
            'count'       => count($this->items),
            'limit'       => $this->limit,
            'has_more'    => $this->hasMore,
            'next_cursor' => $this->nextCursor(),
        ];
    }
}
