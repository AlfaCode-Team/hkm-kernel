<?php

declare(strict_types=1);

namespace Plugins\User\Application\Ports;

use Plugins\User\API\DTOs\ListFeedbackQuery;
use Plugins\User\Domain\Entities\FeedbackEntry;

/**
 * Internal persistence port for user feedback (DIP seam).
 *
 * Backed by the TENANT-routed DatabasePort — the request's tenant database,
 * NOT the central connection. The service depends on this interface so it can
 * be unit-tested with an in-memory fake.
 */
interface FeedbackStore
{
    public function insert(FeedbackEntry $entry): void;

    public function find(string $feedbackId): ?FeedbackEntry;

    /**
     * @return array{0: list<FeedbackEntry>, 1: bool} [entries, hasMore]
     */
    public function paginate(ListFeedbackQuery $query): array;

    /** Persist a status transition. Returns false if the row no longer exists. */
    public function updateStatus(string $feedbackId, string $status): bool;
}
