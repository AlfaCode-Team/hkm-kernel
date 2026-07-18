<?php

declare(strict_types=1);

namespace Plugins\User\Infrastructure\Http\Controllers;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use Plugins\User\API\DTOs\ListFeedbackQuery;
use Plugins\User\API\DTOs\SubmitFeedbackDTO;
use Plugins\User\Application\Services\FeedbackService;
use Project\Http\Controllers\ApiController;

/**
 * Thin HTTP boundary for user feedback — DTO → service → Response. No business
 * logic here. Authorization and validation live in the service / DTOs.
 *
 * RequestAware (via ApiController): actions take route params only; the active
 * request is available as $this->resolveRequest().
 */
final class FeedbackController extends ApiController
{
    public function __construct(
        private readonly FeedbackService $feedback,
    ) {}

    public function submit(): Response
    {
        $dto = SubmitFeedbackDTO::fromRequest($this->resolveRequest());
        return $this->created($this->feedback->submit($dto)->toArray());
    }

    public function show(string $id): Response
    {
        return $this->okOrNotFound(
            $this->feedback->find($id)?->toArray(),
            "Feedback [{$id}] not found.",
        );
    }

    public function index(): Response
    {
        $page = $this->feedback->list(ListFeedbackQuery::fromRequest($this->resolveRequest()));

        return Response::json([
            'data' => array_map(static fn($f) => $f->toArray(), $page->items),
            'meta' => $page->meta(),
        ]);
    }

    public function updateStatus(string $id): Response
    {
        $status = (string) $this->resolveRequest()->input('status', '');
        $entry  = $this->feedback->updateStatus($id, $status);

        return $this->okOrNotFound($entry?->toArray(), "Feedback [{$id}] not found.");
    }
}
