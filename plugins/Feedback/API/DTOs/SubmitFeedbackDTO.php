<?php

declare(strict_types=1);

namespace Plugins\User\API\DTOs;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ValidationException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use Plugins\User\Domain\ValueObjects\FeedbackCategory;
use Plugins\User\Domain\ValueObjects\FeedbackMessage;
use Plugins\User\Domain\ValueObjects\FeedbackRating;

/**
 * Validated feedback-submission input. Field shape is validated HERE; the value
 * objects enforce the deeper rules (category whitelist, 1–5 rating, message
 * bounds). The submitter id is NEVER taken from the request body — it comes from
 * the authenticated Identity in the service, so a client can't submit as someone
 * else.
 */
final readonly class SubmitFeedbackDTO
{
    public function __construct(
        public ?FeedbackCategory $category,
        public ?FeedbackRating $rating,
        public FeedbackMessage $message,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $errors = [];

        $category = null;
        try {
            $category = FeedbackCategory::fromNullable($request->input('category'));
        } catch (\DomainException $e) {
            $errors['category'] = $e->getMessage();
        }

        $rating = null;
        try {
            $rating = FeedbackRating::fromNullable($request->input('rating'));
        } catch (\DomainException $e) {
            $errors['rating'] = $e->getMessage();
        }

        $message = null;
        try {
            $message = FeedbackMessage::fromString((string) $request->input('message', ''));
        } catch (\DomainException $e) {
            $errors['message'] = $e->getMessage();
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        /** @var FeedbackMessage $message */
        return new self(category: $category, rating: $rating, message: $message);
    }
}
