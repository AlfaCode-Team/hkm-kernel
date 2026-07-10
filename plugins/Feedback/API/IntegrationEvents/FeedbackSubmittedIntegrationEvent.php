<?php

declare(strict_types=1);

namespace Plugins\Feedback\API\IntegrationEvents;

use AlfacodeTeam\PhpServicePlatform\Kernel\Events\Contracts\IntegrationEventContract;

/**
 * Cross-module announcement that a user submitted feedback. Primitives only —
 * carries the public feedback id and submitter ULID, never the message body
 * (which may contain sensitive free text).
 */
final readonly class FeedbackSubmittedIntegrationEvent implements IntegrationEventContract
{
    public string $version;

    public function __construct(
        public string $feedbackId,
        public string $userId,
        public ?string $category,
        public ?int $rating,
        public string $occurredAt,
    ) {
        $this->version = '1.0';
    }

    public function name(): string
    {
        return 'feedback.submitted';
    }

    public function version(): string
    {
        return $this->version;
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return [
            'feedbackId' => $this->feedbackId,
            'userId'     => $this->userId,
            'category'   => $this->category,
            'rating'     => $this->rating,
            'occurredAt' => $this->occurredAt,
            'version'    => $this->version,
        ];
    }
}
