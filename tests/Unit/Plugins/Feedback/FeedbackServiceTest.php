<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Feedback;

use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\SecurityException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ValidationException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\Feedback\API\DTOs\ListFeedbackQuery;
use Plugins\Feedback\API\DTOs\SubmitFeedbackDTO;
use Plugins\Feedback\Application\Services\FeedbackService;
use Plugins\Audit\Application\Services\AuditService;
use Psr\Container\ContainerInterface;
use Tests\Unit\Plugins\Feedback\Support\FakeFeedbackStore;
use Tests\Unit\Plugins\User\FakeRequest;

#[CoversClass(FeedbackService::class)]
final class FeedbackServiceTest extends TestCase
{
    private FakeFeedbackStore $store;

    protected function setUp(): void
    {
        $this->store = new FakeFeedbackStore();
    }

    private function service(Identity $identity): FeedbackService
    {
        return new FeedbackService(
            repository: $this->store,
            eventBus:   new EventBus($this->emptyContainer()),
            identity:   $identity,
            audit:      new AuditService(writer: null, sink: static fn(string $l) => null, actorId: 'actor'),
        );
    }

    private function emptyContainer(): ContainerInterface
    {
        return new class implements ContainerInterface {
            public function get(string $id): mixed { throw new \RuntimeException('no bindings'); }
            public function has(string $id): bool { return false; }
        };
    }

    private function submitDto(array $data): SubmitFeedbackDTO
    {
        return SubmitFeedbackDTO::fromRequest(FakeRequest::with($data));
    }

    private function user(string $id, array $permissions = []): Identity
    {
        return new Identity($id, 'tenant-1', [], $permissions, 'jwt');
    }

    // ── submit ────────────────────────────────────────────────────────────────

    public function test_guest_cannot_submit(): void
    {
        $this->expectException(SecurityException::class);
        $this->service(Identity::guest())->submit($this->submitDto(['message' => 'Hi']));
    }

    public function test_authenticated_user_submits_and_is_attributed_to_identity(): void
    {
        $svc = $this->service($this->user('user-A'));

        $dto    = $this->submitDto(['category' => 'payments', 'rating' => '4', 'message' => 'Great']);
        $result = $svc->submit($dto);

        $this->assertSame('user-A', $result->userId());
        $this->assertSame('payments', $result->category()?->value);
        $this->assertSame(4, $result->rating()?->value());
        $this->assertSame('received', $result->status()->value);
        $this->assertNotNull($this->store->find($result->id()->value()));
    }

    public function test_invalid_category_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->submitDto(['category' => 'not_a_category', 'message' => 'Hi']);
    }

    public function test_empty_message_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->submitDto(['message' => '   ']);
    }

    public function test_fractional_rating_is_a_validation_error_not_a_type_error(): void
    {
        // A JSON float rating must surface as 422, never an uncaught TypeError.
        $this->expectException(ValidationException::class);
        $this->submitDto(['rating' => 4.5, 'message' => 'Hi']);
    }

    public function test_integer_valued_float_rating_is_accepted(): void
    {
        $result = $this->service($this->user('user-A'))
            ->submit($this->submitDto(['rating' => 4.0, 'message' => 'Hi']));

        $this->assertSame(4, $result->rating()?->value());
    }

    // ── read (self-or-admin) ────────────────────────────────────────────────────

    public function test_owner_can_read_own_feedback(): void
    {
        $owner = $this->user('user-A');
        $id    = $this->service($owner)->submit($this->submitDto(['message' => 'Mine']))->id()->value();

        $this->assertSame('user-A', $this->service($owner)->find($id)?->userId());
    }

    public function test_other_user_cannot_read_foreign_feedback(): void
    {
        $id = $this->service($this->user('user-A'))->submit($this->submitDto(['message' => 'Mine']))->id()->value();

        $this->expectException(SecurityException::class);
        $this->service($this->user('user-B'))->find($id);
    }

    public function test_manager_can_read_any_feedback(): void
    {
        $id = $this->service($this->user('user-A'))->submit($this->submitDto(['message' => 'Mine']))->id()->value();

        $manager = $this->user('admin', ['feedback:manage']);
        $this->assertSame('user-A', $this->service($manager)->find($id)?->userId());
    }

    // ── list / triage (manager only) ────────────────────────────────────────────

    public function test_non_manager_cannot_list(): void
    {
        $this->expectException(SecurityException::class);
        $this->service($this->user('user-A'))->list(ListFeedbackQuery::fromRequest(FakeRequest::with([], 'GET')));
    }

    public function test_status_can_only_move_forward(): void
    {
        $id      = $this->service($this->user('user-A'))->submit($this->submitDto(['message' => 'Mine']))->id()->value();
        $manager = $this->user('admin', ['feedback:manage']);

        // received → resolved is allowed (forward).
        $this->assertSame('resolved', $this->service($manager)->updateStatus($id, 'resolved')?->status()->value);
    }
}
