<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\User;

use AlfacodeTeam\PhpServicePlatform\Kernel\Database\TransactionManager;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\DomainEventCollector;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;
use Psr\Container\ContainerInterface;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\SecurityException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ValidationException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\User\API\DTOs\ListUsersQuery;
use Plugins\User\API\DTOs\RegisterUserDTO;
use Plugins\User\API\DTOs\UpdateUserDTO;
use Plugins\User\Application\Services\UserService;
use Plugins\User\Domain\Entities\User;
use Plugins\User\Domain\ValueObjects\Email;
use Plugins\User\Domain\ValueObjects\Username;
use Plugins\Audit\Application\Services\AuditService;
use Tests\Unit\Plugins\User\Support\FakeCache;
use Tests\Unit\Plugins\User\Support\FakeDatabasePort;
use Tests\Unit\Plugins\User\Support\FakeHasher;
use Tests\Unit\Plugins\User\Support\FakeOutbox;
use Tests\Unit\Plugins\User\Support\FakeUserStore;

#[CoversClass(UserService::class)]
final class UserServiceTest extends TestCase
{
    private FakeUserStore $store;
    private FakeOutbox $outbox;
    private FakeHasher $hasher;
    private FakeCache $cache;

    protected function setUp(): void
    {
        $this->store  = new FakeUserStore();
        $this->outbox = new FakeOutbox();
        $this->hasher = new FakeHasher();
        $this->cache  = new FakeCache();
    }

    private function service(Identity $identity): UserService
    {
        return new UserService(
            repository:  $this->store,
            transaction: new TransactionManager(new FakeDatabasePort()),
            collector:   new DomainEventCollector(),
            outbox:      $this->outbox,
            eventBus:    new EventBus($this->emptyContainer()),
            hasher:      $this->hasher,
            identity:    $identity,
            cache:       $this->cache,
            audit:       new AuditService(writer: null, sink: static fn(string $l) => null, actorId: 'actor'),
        );
    }

    private function emptyContainer(): ContainerInterface
    {
        return new class implements ContainerInterface {
            public function get(string $id): mixed { throw new \RuntimeException('no bindings'); }
            public function has(string $id): bool { return false; }
        };
    }

    private function seedUser(string $username = 'janedoe', string $email = 'janedoe@example.com'): User
    {
        $user = User::register(
            username:     Username::fromString($username),
            email:        Email::fromString($email),
            passwordHash: $this->hasher->make('Sup3rSecret!!'),
        );
        $this->store->insert($user);
        return $user;
    }

    private function registerRequest(array $data): RegisterUserDTO
    {
        return RegisterUserDTO::fromRequest(FakeRequest::with($data));
    }

    // ── registration ────────────────────────────────────────────────────────

    public function test_register_persists_user_and_enqueues_event(): void
    {
        $svc = $this->service(Identity::guest());

        $dto = $this->registerRequest([
            'username' => 'newbie',
            'email'    => 'new@example.com',
            'password' => 'Sup3rSecret!!',
        ]);
        $result = $svc->register($dto);

        $this->assertSame('newbie', $result->username);
        $this->assertFalse($result->emailVerified);
        $this->assertContains('user.registered', $this->outbox->names());
        $this->assertNotNull($this->store->find($result->id));
    }

    public function test_register_rejects_duplicate(): void
    {
        $this->seedUser('taken', 'taken@example.com');
        $svc = $this->service(Identity::guest());

        $this->expectException(ValidationException::class);
        $svc->register($this->registerRequest([
            'username' => 'taken',
            'email'    => 'taken@example.com',
            'password' => 'Sup3rSecret!!',
        ]));
    }

    public function test_register_rejects_weak_password(): void
    {
        $this->expectException(ValidationException::class);
        $this->registerRequest([
            'username' => 'weakling',
            'email'    => 'weak@example.com',
            'password' => 'short',
        ]);
    }

    // ── authorization ─────────────────────────────────────────────────────────

    public function test_list_requires_permission(): void
    {
        $svc = $this->service(Identity::asUser('u1'));
        $this->expectException(SecurityException::class);
        $svc->list(new ListUsersQuery(25, null));
    }

    public function test_admin_can_list(): void
    {
        $this->seedUser();
        $svc = $this->service(Identity::asAdmin());

        $page = $svc->list(new ListUsersQuery(25, null));
        $this->assertCount(1, $page->items);
    }

    public function test_user_cannot_read_another_user(): void
    {
        $other = $this->seedUser();
        $svc   = $this->service(Identity::asUser('not-the-owner'));

        $this->expectException(SecurityException::class);
        $svc->find($other->id());
    }

    public function test_user_can_read_self(): void
    {
        $me  = $this->seedUser();
        $svc = $this->service(Identity::asUser($me->id()));

        $this->assertNotNull($svc->find($me->id()));
    }

    // ── update / delete events ─────────────────────────────────────────────────

    public function test_update_changes_username_and_emits_event(): void
    {
        $me  = $this->seedUser('oldname');
        $svc = $this->service(Identity::asUser($me->id()));

        $dto = UpdateUserDTO::fromRequest(FakeRequest::with(['username' => 'newname']));
        $result = $svc->update($me->id(), $dto);

        $this->assertSame('newname', $result?->username);
        $this->assertContains('user.updated', $this->outbox->names());
        $this->assertSame(2, $this->store->find($me->id())?->version());
    }

    public function test_delete_self_emits_event(): void
    {
        $me  = $this->seedUser();
        $svc = $this->service(Identity::asUser($me->id()));

        $this->assertTrue($svc->delete($me->id()));
        $this->assertContains('user.deleted', $this->outbox->names());
        $this->assertNull($this->store->find($me->id()));
    }

    // ── login / lockout ─────────────────────────────────────────────────────────

    public function test_verify_credentials_success(): void
    {
        $this->seedUser('janedoe', 'janedoe@example.com');
        // jane is Pending by default → inactive for login; activate via verify path.
        $active = $this->activeUser('active', 'active@example.com', 'Sup3rSecret!!');
        $svc = $this->service(Identity::guest());

        $this->assertNotNull($svc->verifyCredentials('active', 'Sup3rSecret!!'));
        $this->assertSame($active, $active); // sanity
    }

    public function test_verify_credentials_wrong_password_returns_null(): void
    {
        $this->activeUser('active', 'active@example.com', 'Sup3rSecret!!');
        $svc = $this->service(Identity::guest());

        $this->assertNull($svc->verifyCredentials('active', 'WrongPass123!'));
    }

    public function test_lockout_after_repeated_failures(): void
    {
        $this->activeUser('active', 'active@example.com', 'Sup3rSecret!!');
        $svc = $this->service(Identity::guest());

        for ($i = 0; $i < 5; $i++) {
            $svc->verifyCredentials('active', 'WrongPass123!');
        }

        // Even the CORRECT password is now refused while locked out.
        $this->assertNull($svc->verifyCredentials('active', 'Sup3rSecret!!'));
    }

    public function test_rehash_on_login_when_needed(): void
    {
        $u = $this->activeUser('active', 'active@example.com', 'Sup3rSecret!!');
        $this->hasher->needsRehash = true;
        $svc = $this->service(Identity::guest());

        $this->assertNotNull($svc->verifyCredentials('active', 'Sup3rSecret!!'));
        $this->assertArrayHasKey($u->id(), $this->store->rehashed);
    }

    /** Seed an already-active, email-verified user (login-eligible). */
    private function activeUser(string $username, string $email, string $password): User
    {
        $user = User::register(
            username:     Username::fromString($username),
            email:        Email::fromString($email),
            passwordHash: $this->hasher->make($password),
        );
        $user->verifyEmail(); // Pending → Active
        $user->commitChanges();
        $user->releaseEvents();
        $this->store->insert($user);
        return $user;
    }
}
