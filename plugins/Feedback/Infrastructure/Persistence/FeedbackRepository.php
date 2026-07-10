<?php

declare(strict_types=1);

namespace Plugins\Feedback\Infrastructure\Persistence;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\RepositoryException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use Plugins\Feedback\API\DTOs\ListFeedbackQuery;
use Plugins\Feedback\Application\Ports\FeedbackStore;
use Plugins\Feedback\Domain\Entities\FeedbackEntry;

/**
 * FeedbackRepository — DatabasePort ONLY.
 *
 * The injected DatabasePort is the request's TENANT connection (rebound by
 * Tenancy's TenantContextStage), so every row lands in the submitter's tenant
 * database. There is therefore NO tenant_id column — the database IS the tenant
 * boundary. The `user_feedback` table is owned by the plugin's tenant-template
 * migration; this class never creates or alters schema.
 *
 * Invariants:
 *   - Every query is parameterised (no interpolation).
 *   - \PDOException never escapes — it is translated to RepositoryException.
 *   - Exception context carries IDs only — never the message body (no PII).
 */
final class FeedbackRepository implements FeedbackStore
{
    private const TABLE = 'user_feedback';

    private const COLUMNS = 'feedback_id, user_id, category, rating, message, status, created_at';

    public function __construct(
        private readonly DatabasePort $db,
    ) {}

    public function insert(FeedbackEntry $entry): void
    {
        try {
            $this->db->execute(
                'INSERT INTO ' . self::TABLE . '
                    (user_id, feedback_id, category, rating, message, status, created_at)
                 VALUES
                    (:user_id, :feedback_id, :category, :rating, :message, :status, :created_at)',
                [
                    'user_id'     => $entry->userId(),
                    'feedback_id' => $entry->id()->value(),
                    'category'    => $entry->category()?->value,
                    'rating'      => $entry->rating()?->value(),
                    'message'     => $entry->message()->value(),
                    'status'      => $entry->status()->value,
                    'created_at'  => $entry->createdAt()->format('Y-m-d H:i:s'),
                ],
            );
        } catch (\Throwable $e) {
            throw new RepositoryException(
                'Failed to insert feedback.',
                layer: 'repository.feedback',
                context: ['feedbackId' => $entry->id()->value()],
                previous: $e,
            );
        }
    }

    public function find(string $feedbackId): ?FeedbackEntry
    {
        try {
            $row = $this->db->queryOne(
                'SELECT ' . self::COLUMNS . ' FROM ' . self::TABLE . '
                 WHERE feedback_id = :id LIMIT 1',
                ['id' => $feedbackId],
            );
        } catch (\Throwable $e) {
            throw new RepositoryException(
                'Failed to load feedback.',
                layer: 'repository.feedback',
                previous: $e,
            );
        }

        return $row === null ? null : self::hydrate($row);
    }

    public function paginate(ListFeedbackQuery $query): array
    {
        $params = ['limit' => $query->limit + 1];
        $where  = [];

        if ($query->status !== null) {
            $where[] = 'status = :status';
            $params['status'] = $query->status->value;
        }

        if ($query->after !== null) {
            // Keyset on the internal id resolved from the opaque public cursor.
            $where[] = 'id < (SELECT id FROM ' . self::TABLE . ' WHERE feedback_id = :after)';
            $params['after'] = $query->after;
        }

        $clause = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

        try {
            $rows = $this->db->query(
                'SELECT ' . self::COLUMNS . ' FROM ' . self::TABLE . $clause . '
                 ORDER BY id DESC
                 LIMIT :limit',
                $params,
            );
        } catch (\Throwable $e) {
            throw new RepositoryException('Failed to list feedback.', layer: 'repository.feedback', previous: $e);
        }

        $hasMore = count($rows) > $query->limit;
        if ($hasMore) {
            array_pop($rows);
        }

        return [array_map(static fn(array $r): FeedbackEntry => self::hydrate($r), $rows), $hasMore];
    }

    public function updateStatus(string $feedbackId, string $status): bool
    {
        try {
            $affected = $this->db->execute(
                'UPDATE ' . self::TABLE . ' SET status = :status WHERE feedback_id = :id',
                ['status' => $status, 'id' => $feedbackId],
            );
        } catch (\Throwable $e) {
            throw new RepositoryException(
                'Failed to update feedback status.',
                layer: 'repository.feedback',
                context: ['feedbackId' => $feedbackId],
                previous: $e,
            );
        }

        return $affected > 0;
    }

    /** @param array<string, mixed> $row */
    private static function hydrate(array $row): FeedbackEntry
    {
        return FeedbackEntry::reconstitute($row);
    }
}
