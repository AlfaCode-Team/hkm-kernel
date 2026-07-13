<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Auth;

use AlfacodeTeam\PhpServicePlatform\Kernel\Container\CoreContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Container\ModuleContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Database\TransactionManager;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\DomainEventCollector;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\SessionPort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\Auth\API\Contracts\AuthServiceContract;
use Plugins\Auth\Application\Services\AuthService;
use Plugins\Auth\Infrastructure\Http\Stages\SessionAuthStage;
use Plugins\Auth\Infrastructure\Persistence\PersonalAccessTokenRepository;
use Plugins\Cookie\Infrastructure\CookieJar;
use Plugins\User\API\Contracts\UserServiceContract;
use Plugins\User\Application\Services\UserService;
use Plugins\User\Domain\Entities\User;
use Plugins\Audit\Application\Services\AuditService;
use Tests\Unit\Plugins\Auth\Support\FakeSession;
use Tests\Unit\Plugins\Auth\Support\RecordingDatabasePort;
use Tests\Unit\Plugins\User\Support\FakeCache;
use Tests\Unit\Plugins\User\Support\FakeDatabasePort;
use Tests\Unit\Plugins\User\Support\FakeHasher;
use Tests\Unit\Plugins\User\Support\FakeOutbox;
use Tests\Unit\Plugins\User\Support\FakeUserStore;

/**
 * Integration test for the remember-me resurrection path: a request with NO
 * live session but a valid recaller cookie should be re-authenticated, the
 * session re-opened, and the recaller rotated.
 */
#[CoversClass(SessionAuthStage::class)]
final class SessionAuthStageRememberTest extends TestCase
{
    private FakeUserStore $store;
    private FakeSession $session;

    private const USER_ID = 'user-remember-1';
    private const TOKEN   = 'plaintext-remember-token';

    private function container(): ModuleContainer
    {
        $this->store   = new FakeUserStore();
        $this->session = new FakeSession();

        // Seed a verified (login-eligible) user whose remember-token hash matches.
        $user = User::reconstitute([
            'user_id'           => self::USER_ID,
            'username'          => 'remembered',
            'email'             => 'remembered@example.com',
            'password_hash'     => str_repeat('a', 60),
            'email_verified_at' => '2026-01-01 00:00:00',
            'created_at'        => '2026-01-01 00:00:00',
        ]);
        $this->store->insert($user);
        $this->store->rememberTokens[self::USER_ID] = hash('sha256', self::TOKEN);

        $users = new UserService(
            repository:  $this->store,
            transaction: new TransactionManager(new FakeDatabasePort()),
            collector:   new DomainEventCollector(),
            outbox:      new FakeOutbox(),
            eventBus:    new EventBus(new CoreContainer()),
            hasher:      new FakeHasher(),
            identity:    Identity::guest(),
            cache:       new FakeCache(),
            audit:       new AuditService(writer: null, sink: static fn(string $l) => null, actorId: 'actor'),
        );

        $auth = new AuthService(
            tokens:    new PersonalAccessTokenRepository(new RecordingDatabasePort()),
            hasher:    new FakeHasher(),
            jwtSecret: 'a-test-secret-at-least-32-chars-long!!',
        );

        $container = new ModuleContainer(new CoreContainer());
        $container->setScope('auth.identity');
        // CookieJar with no encrypter → read() returns the raw cookie value.
        $container->instance(CookieJar::class, new CookieJar());
        $container->instance(UserServiceContract::class, $users);
        $container->instance(AuthServiceContract::class, $auth);
        $container->instance(SessionPort::class, $this->session);

        return $container;
    }

    private function request(ModuleContainer $container, array $cookies): Request
    {
        return Request::build(method: 'GET', path: '/dashboard', cookies: $cookies)
            ->withContainer($container);
    }

    public function test_valid_recaller_resurrects_session_and_rotates_token(): void
    {
        $container = $this->container();
        $request   = $this->request($container, [
            SessionAuthStage::RECALLER_COOKIE => self::USER_ID . '|' . self::TOKEN,
        ]);

        $captured = null;
        (new SessionAuthStage())->handle($request, function (Request $req) use (&$captured): Response {
            $captured = $req;
            return Response::noContent();
        });

        // Identity rebuilt as a session credential.
        self::assertNotNull($captured->identity());
        self::assertSame(self::USER_ID, $captured->identity()->userId);
        self::assertSame('session', $captured->identity()->tokenType);

        // Session opened (regenerated for fixation defence) and user stored.
        self::assertSame(self::USER_ID, $this->session->get(AuthService::SESSION_USER));
        self::assertGreaterThanOrEqual(1, $this->session->regenerations);

        // Recaller rotated: the old token no longer matches the stored hash.
        self::assertNotSame(hash('sha256', self::TOKEN), $this->store->rememberTokens[self::USER_ID]);

        // A fresh recaller cookie was queued.
        self::assertTrue($container->make(CookieJar::class)->hasQueued(SessionAuthStage::RECALLER_COOKIE));
    }

    public function test_forged_recaller_is_ignored_and_stays_guest(): void
    {
        $container = $this->container();
        $request   = $this->request($container, [
            SessionAuthStage::RECALLER_COOKIE => self::USER_ID . '|wrong-token',
        ]);

        $captured = null;
        (new SessionAuthStage())->handle($request, function (Request $req) use (&$captured): Response {
            $captured = $req;
            return Response::noContent();
        });

        // No identity attached; no session opened; token untouched.
        self::assertNull($captured->identity());
        self::assertNull($this->session->get(AuthService::SESSION_USER));
        self::assertSame(hash('sha256', self::TOKEN), $this->store->rememberTokens[self::USER_ID]);
    }

    public function test_mismatched_owner_id_is_rejected(): void
    {
        $container = $this->container();
        // Correct token but a different user id in the cookie's first segment.
        $request = $this->request($container, [
            SessionAuthStage::RECALLER_COOKIE => 'someone-else|' . self::TOKEN,
        ]);

        $captured = null;
        (new SessionAuthStage())->handle($request, function (Request $req) use (&$captured): Response {
            $captured = $req;
            return Response::noContent();
        });

        self::assertNull($captured->identity());
    }
}
