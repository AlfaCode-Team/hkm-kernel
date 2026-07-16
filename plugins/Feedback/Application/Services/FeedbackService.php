<?php

declare(strict_types=1);

namespace Plugins\Feedback\Application\Services;

use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\SecurityException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ServiceException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ValidationException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use Plugins\Feedback\API\DTOs\FeedbackPage;
use Plugins\Feedback\API\DTOs\ListFeedbackQuery;
use Plugins\Feedback\API\DTOs\SubmitFeedbackDTO;
use Plugins\Feedback\API\IntegrationEvents\FeedbackSubmittedIntegrationEvent;
use Plugins\Feedback\Application\Ports\FeedbackStore;
use Plugins\Feedback\Domain\Entities\FeedbackEntry;
use Plugins\Feedback\Domain\ValueObjects\FeedbackStatus;
use Plugins\Audit\API\Contracts\AuditServiceContract;

/**
 * FeedbackService — orchestrates user feedback.
 *
 * Security posture:
 *   - Authorization lives HERE. Submitting requires an authenticated identity;
 *     the submitter id is taken from the Identity, never from the request body.
 *   - Reading one entry is self-or-admin; listing/triage is admin-only
 *     (feedback:manage).
 *   - Writes are single tenant-scoped statements (atomic on their own); the
 *     integration event is dispatched only AFTER the write succeeds. The kernel
 *     TransactionManager is intentionally NOT used — it is bound to the central
 *     connection, not the request's tenant connection.
 *   - Feedback rows live in the request's TENANT database (the repository is
 *     bound to the tenant-routed DatabasePort).
 */
final class FeedbackService
{
    private const PERMISSION_MANAGE = 'feedback:manage';

    public function __construct(
        private readonly FeedbackStore $repository,
        private readonly EventBus $eventBus,
        private readonly Identity $identity,
        private readonly AuditServiceContract $audit,
    ) {}

    public function submit(SubmitFeedbackDTO $dto): FeedbackEntry
    {
        // Must be authenticated — feedback is always attributed to a real user.
        if ($this->identity->isGuest()) {
            throw new SecurityException(
                'feedback.submit.unauthenticated',
                layer: 'service.feedback',
            );
        }

        $entry = FeedbackEntry::submit(
            userId:   $this->identity->userId,
            category: $dto->category,
            rating:   $dto->rating,
            message:  $dto->message,
        );

        // A single tenant-scoped INSERT is atomic on its own. We deliberately do
        // NOT use the kernel TransactionManager here: it is constructed against
        // the CENTRAL DatabasePort, whereas this repository writes to the
        // request's TENANT connection — wrapping it would open an idle central
        // transaction that never covers the tenant write.
        try {
            $this->repository->insert($entry);
        } catch (\Throwable $e) {
            throw $this->wrap($e, 'feedback.submit.failed');
        }

        // Integration event AFTER the write succeeds.
        $this->eventBus->dispatch(new FeedbackSubmittedIntegrationEvent(
            feedbackId: $entry->id()->value(),
            userId:     $entry->userId(),
            category:   $entry->category()?->value,
            rating:     $entry->rating()?->value(),
            occurredAt: $entry->createdAt()->format(\DateTimeInterface::RFC3339),
        ));

        $this->audit->record('feedback.submitted', meta: ['feedbackId' => $entry->id()->value()]);

        return $entry;
    }

    public function find(string $feedbackId): ?FeedbackEntry
    {
        $entry = $this->repository->find($feedbackId);
        if ($entry === null) {
            return null;
        }

        // Self-or-admin: a user may read only their own feedback.
        if (!$entry->isOwnedBy($this->identity->userId)
            && !$this->identity->hasPermission(self::PERMISSION_MANAGE)) {
            throw new SecurityException(
                'feedback.read.forbidden',
                layer: 'service.feedback',
                context: ['feedbackId' => $feedbackId],
            );
        }

        return $entry;
    }

    public function list(ListFeedbackQuery $query): FeedbackPage
    {
        $this->requireManage();

        [$entries, $hasMore] = $this->repository->paginate($query);

        return new FeedbackPage(
            items:   $entries,
            hasMore: $hasMore,
            limit:   $query->limit,
        );
    }

    public function updateStatus(string $feedbackId, string $status): ?FeedbackEntry
    {
        $this->requireManage();

        $entry = $this->repository->find($feedbackId);
        if ($entry === null) {
            return null;
        }

        // Validate + apply the transition on the entity (forward-only) before
        // touching the database — an illegal jump throws a 422.
        try {
            $entry->transitionTo(FeedbackStatus::fromString($status));
        } catch (\DomainException $e) {
            throw new ValidationException(['status' => $e->getMessage()]);
        }

        try {
            $updated = $this->repository->updateStatus($feedbackId, $entry->status()->value);
        } catch (\Throwable $e) {
            throw $this->wrap($e, 'feedback.update_status.failed', ['feedbackId' => $feedbackId]);
        }

        // The row vanished between read and write (concurrent delete).
        if (!$updated) {
            return null;
        }

        $this->audit->record('feedback.status_changed', meta: [
            'feedbackId' => $feedbackId,
            'status'     => $entry->status()->value,
        ]);

        return $entry;
    }

    private function requireManage(): void
    {
        if (!$this->identity->hasPermission(self::PERMISSION_MANAGE)) {
            throw new SecurityException(
                'feedback.manage.forbidden',
                layer: 'service.feedback',
            );
        }
    }

    private function wrap(\Throwable $e, string $code, array $context = []): \Throwable
    {
        // Preserve typed faults so the kernel maps them to the right HTTP status.
        if ($e instanceof ServiceException
            || $e instanceof ValidationException
            || $e instanceof SecurityException
            || $e instanceof \AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\DomainException
        ) {
            return $e;
        }

        return new ServiceException($code, layer: 'service.feedback', context: $context, previous: $e);
    }
}
