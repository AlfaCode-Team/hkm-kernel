<?php

declare(strict_types=1);

namespace Plugins\Feedback\Domain\Entities;

use Plugins\Feedback\Domain\ValueObjects\FeedbackCategory;
use Plugins\Feedback\Domain\ValueObjects\FeedbackId;
use Plugins\Feedback\Domain\ValueObjects\FeedbackMessage;
use Plugins\Feedback\Domain\ValueObjects\FeedbackRating;
use Plugins\Feedback\Domain\ValueObjects\FeedbackStatus;
use Project\Support\Entity\Entity;

/**
 * FeedbackEntry aggregate — mirrors the TENANT-scoped `user_feedback` table.
 *
 * `userId` is the submitter's central ULID (users.user_id). The entry lives in
 * the submitter's tenant database; there is no cross-DB foreign key. Built on
 * the shared {@see Entity} attribute-bag base, keyed by DB column; the
 * value-object accessors rebuild their VOs from the stored scalars.
 */
final class FeedbackEntry extends Entity
{
    protected string $primaryKey = 'feedback_id';

    /** @var array<string, string> */
    protected array $casts = [
        'rating'     => '?int',
        'created_at' => 'datetime',
    ];

    /**
     * Submit brand-new feedback. The cross-module announcement is the
     * FeedbackSubmittedIntegrationEvent dispatched by the service after the
     * write; this aggregate records no in-process domain events.
     */
    public static function submit(
        string $userId,
        ?FeedbackCategory $category,
        ?FeedbackRating $rating,
        FeedbackMessage $message,
    ): self {
        if ($userId === '' || mb_strlen($userId) > 31) {
            throw new \DomainException('FeedbackEntry requires a valid user id.');
        }

        $e = (new self())->forceFill([
            'feedback_id' => FeedbackId::generate()->value(),
            'user_id'     => $userId,
            'category'    => $category?->value,
            'rating'      => $rating?->value(),
            'message'     => $message->value(),
            'status'      => FeedbackStatus::Received->value,
            'created_at'  => new \DateTimeImmutable(),
        ]);
        $e->syncOriginal();

        return $e;
    }

    /** Advance triage state (forward-only). */
    public function transitionTo(FeedbackStatus $next): void
    {
        $current = $this->status();
        if ($next === $current) {
            return;
        }
        if (!$current->canTransitionTo($next)) {
            throw new \DomainException(
                "Cannot move feedback from {$current->value} to {$next->value}."
            );
        }
        $this->setAttribute('status', $next->value);
    }

    public function isOwnedBy(string $userId): bool
    {
        return hash_equals($this->userId(), $userId);
    }

    public function id(): FeedbackId                 { return FeedbackId::fromString($this->getString('feedback_id')); }
    public function userId(): string                 { return $this->getString('user_id'); }
    public function category(): ?FeedbackCategory    { $v = $this->getRawAttribute('category'); return $v === null ? null : FeedbackCategory::from((string) $v); }
    public function rating(): ?FeedbackRating        { $v = $this->getRawAttribute('rating'); return $v === null ? null : FeedbackRating::of((int) $v); }
    public function message(): FeedbackMessage       { return FeedbackMessage::fromString($this->getString('message')); }
    public function status(): FeedbackStatus         { return FeedbackStatus::from($this->getString('status')); }
    public function createdAt(): \DateTimeImmutable  { return $this->getDate('created_at') ?? new \DateTimeImmutable(); }

    /** @return array<string, mixed> Camel-cased API shape (not the DB shape). */
    public function toArray(bool $onlyChanged = false): array
    {
        return [
            'feedbackId' => $this->id()->value(),
            'userId'     => $this->userId(),
            'category'   => $this->category()?->value,
            'rating'     => $this->rating()?->value(),
            'message'    => $this->message()->value(),
            'status'     => $this->status()->value,
            'createdAt'  => $this->createdAt()->format(\DateTimeInterface::RFC3339),
        ];
    }
}
