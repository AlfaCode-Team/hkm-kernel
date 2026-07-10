<?php

declare(strict_types=1);

namespace Plugins\User\Application\Services;

use AlfacodeTeam\PhpServicePlatform\Kernel\Database\TransactionManager;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\Contracts\DomainEventContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\Contracts\IntegrationEventContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\DomainEventCollector;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\SecurityException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ServiceException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ValidationException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\CachePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\HashingPort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use Plugins\User\API\Contracts\UserServiceContract;
use Plugins\User\API\DTOs\ListUsersQuery;
use Plugins\User\API\DTOs\RegisterUserDTO;
use Plugins\User\API\DTOs\UpdateUserDTO;
use Plugins\User\API\DTOs\UserDTO;
use Plugins\User\API\DTOs\UserPage;
use Plugins\User\API\DTOs\VerifyEmailDTO;
use Plugins\User\API\IntegrationEvents\UserDeletedIntegrationEvent;
use Plugins\User\API\IntegrationEvents\UserRegisteredIntegrationEvent;
use Plugins\User\API\IntegrationEvents\UserUpdatedIntegrationEvent;
use Plugins\User\Application\Ports\BreachChecker;
use Plugins\User\Application\Ports\OutboxPort;
use Plugins\User\Application\Ports\UserStore;
use Plugins\User\Domain\Entities\User;
use Plugins\User\Domain\Events\UserDeletedDomainEvent;
use Plugins\User\Domain\Events\UserRegisteredDomainEvent;
use Plugins\User\Domain\Events\UserUpdatedDomainEvent;
use Plugins\User\Infrastructure\Audit\AuditLogger;

/**
 * UserService — orchestrates the user.management domain.
 *
 * Security posture:
 *   - Passwords hashed via the crypto.services HashingPort (bcrypt); plaintext
 *     is never persisted, logged, or returned. Hashes upgrade transparently on
 *     login when the cost factor changes (rehash-on-login).
 *   - Authorization lives HERE: admins act on anyone; a non-admin only on their
 *     own record. Registration / credential verification are public.
 *   - Login is timing-safe AND rate-limited with per-identifier lockout
 *     (CachePort) to blunt credential stuffing.
 *   - Integration events are written to a transactional OUTBOX inside the same
 *     transaction as the state change (atomic, at-least-once delivery).
 *   - Security-relevant actions are audited (identifiers only, no PII).
 */
final class UserService implements UserServiceContract
{
    /** Constant-time decoy hash so login timing never reveals account existence. */
    private const DECOY_HASH = '$2y$12$xOniTd152PkIwSYuT8kYzeaxR/gfiv2IxUKxKUhOIPGAt6S1kFR3C';

    /** Lockout policy. */
    private const MAX_LOGIN_FAILURES = 5;
    private const LOCKOUT_WINDOW     = 900; // seconds (15 min)

    public function __construct(
        private readonly UserStore $repository,
        private readonly TransactionManager $transaction,
        private readonly DomainEventCollector $collector,
        private readonly OutboxPort $outbox,
        private readonly HashingPort $hasher,
        private readonly Identity $identity,
        private readonly CachePort $cache,
        private readonly AuditLogger $audit,
        private readonly ?BreachChecker $breachChecker = null,
    ) {}

    public function list(ListUsersQuery $query): UserPage
    {
        // Listing every user is an admin-only capability.
        $this->requirePermission('user:list');

        [$users, $hasMore] = $this->repository->paginate($query);

        return new UserPage(
            items:   array_map(static fn(User $u): UserDTO => UserDTO::fromEntity($u), $users),
            hasMore: $hasMore,
            limit:   $query->limit,
        );
    }

    /** Verification token lifetime (seconds) — 24h. */
    private const VERIFICATION_TTL = 86400;

    /**
     * ADMIN / back-office registration. Returns the FULL user record so it can
     * be shown in an admin table. Still arms an email-verification token; when
     * you need the plaintext token to email, use registerPublic() instead.
     */
    public function register(RegisterUserDTO $dto): UserDTO
    {
        [$user] = $this->provision($dto);
        return $user;
    }

    /**
     * PUBLIC self-signup. Returns ONLY the plaintext verification token for the
     * caller to email — never the identity record. A public registrant must not
     * receive their id/email/verification state back, so the controller responds
     * with a fixed "pending" status and this token stays server-side.
     */
    public function registerPublic(RegisterUserDTO $dto): string
    {
        [, $token] = $this->provision($dto);
        return $token;
    }

    /**
     * Confirm an email from the PUBLIC (unauthenticated) verification link. The
     * emailed token is matched by its stored SHA-256 hash and must not be
     * expired. One-time: verifyEmail() clears the token on success. Returns
     * false on any miss (unknown/expired/consumed) so a forged token reveals
     * nothing.
     */
    public function verifyEmailByToken(string $token): bool
    {
        if ($token === '') {
            return false;
        }

        $user = $this->repository->findByVerificationTokenHash(hash('sha256', $token));
        if ($user === null) {
            $this->audit->record('user.email_verify.token_miss', []);
            return false;
        }

        $expiresAt = $user->emailVerificationExpiresAt();
        if ($expiresAt === null || $expiresAt < new \DateTimeImmutable()) {
            $this->audit->record('user.email_verify.token_expired', ['userId' => $user->id()]);
            return false;
        }

        $this->collector->beginCollection();
        $this->transaction->begin();
        try {
            $user->verifyEmail();
            if (!$user->commitChanges()) {
                $this->transaction->rollback();
                $this->collector->discard();
                return true; // already verified — idempotent success
            }

            $this->flushEvents($user);
            $this->repository->update($user);
            $this->transaction->commit();
        } catch (\Throwable $e) {
            $this->transaction->rollback();
            $this->collector->discard();
            throw $this->wrap($e, 'user.verify_email.failed', ['id' => $user->id()]);
        }

        $this->collector->release();
        $this->audit->record('user.email_verified', ['userId' => $user->id()]);

        return true;
    }

    /**
     * Shared registration core — arms a verification token and persists identity.
     *
     * @return array{0: UserDTO, 1: string} [record, plaintext verification token]
     */
    private function provision(RegisterUserDTO $dto): array
    {
        // Cheap pre-check for a friendly 422 before we hit the unique index;
        // the index + DuplicateUserException is the authoritative guard.
        if ($this->repository->existsByUsernameOrEmail($dto->username->value(), $dto->email->value())) {
            throw new ValidationException(['username' => 'Username or email is already taken.']);
        }

        $this->assertNotBreached($dto->password);

        // Emailed once; only its hash is stored. Time-boxed + one-time.
        $plainToken = bin2hex(random_bytes(32));
        $expiresAt  = (new \DateTimeImmutable())->modify('+' . self::VERIFICATION_TTL . ' seconds');

        $this->collector->beginCollection();
        $this->transaction->begin();
        try {
            $user = User::register(
                username:     $dto->username,
                email:        $dto->email,
                passwordHash: $this->hasher->make($dto->password),
            );
            $user->startEmailVerification(hash('sha256', $plainToken), $expiresAt);

            // Profile (if submitted) rides on the event for a tenant-side write;
            // it CANNOT join this central identity transaction (different DB).
            $this->flushEvents($user, $dto->tenantId, $dto->profile);
            $this->repository->insert($user);
            $this->transaction->commit();
        } catch (\Throwable $e) {
            $this->transaction->rollback();
            $this->collector->discard();
            throw $this->wrap($e, 'user.register.failed');
        }

        $this->collector->release();
        $this->audit->record('user.registered', ['userId' => $user->id()]);

        return [UserDTO::fromEntity($user), $plainToken];
    }

    public function find(string $id): ?UserDTO
    {
        $this->requireSelfOrPermission($id, 'user:read-any');

        $user = $this->repository->find($id);
        return $user === null ? null : UserDTO::fromEntity($user);
    }

    public function update(string $id, UpdateUserDTO $dto): ?UserDTO
    {
        $this->requireSelfOrPermission($id, 'user:update-any');

        $user = $this->repository->find($id);
        if ($user === null) {
            return null;
        }
        if (!$dto->hasChanges()) {
            return UserDTO::fromEntity($user);
        }

        $newUsername = $dto->username?->value() ?? $user->username();
        $newEmail    = $dto->email?->value() ?? $user->email();
        if ($this->repository->existsByUsernameOrEmail($newUsername, $newEmail, exceptUserId: $id)) {
            throw new ValidationException(['username' => 'Username or email is already taken.']);
        }

        if ($dto->password !== null) {
            $this->assertNotBreached($dto->password);
        }

        $this->collector->beginCollection();
        $this->transaction->begin();
        try {
            if ($dto->username !== null) {
                $user->rename($dto->username);
            }
            if ($dto->email !== null) {
                $user->changeEmail($dto->email);
            }
            if ($dto->password !== null) {
                $user->changePassword($this->hasher->make($dto->password));
            }

            if (!$user->commitChanges()) {
                $this->transaction->rollback();
                $this->collector->discard();
                return UserDTO::fromEntity($user);
            }

            $this->flushEvents($user);
            $this->repository->update($user);
            $this->transaction->commit();
        } catch (\Throwable $e) {
            $this->transaction->rollback();
            $this->collector->discard();
            throw $this->wrap($e, 'user.update.failed', ['id' => $id]);
        }

        $this->collector->release();
        $this->audit->record('user.updated', ['userId' => $id]);

        return UserDTO::fromEntity($user);
    }

    public function verifyEmail(string $id, VerifyEmailDTO $dto): ?UserDTO
    {
        // The caller (or an Auth module) issues the token; here we accept it for
        // the user being acted on. Self-or-admin still applies.
        $this->requireSelfOrPermission($id, 'user:update-any');

        $user = $this->repository->find($id);
        if ($user === null) {
            return null;
        }

        $this->collector->beginCollection();
        $this->transaction->begin();
        try {
            $user->verifyEmail();
            if (!$user->commitChanges()) {
                $this->transaction->rollback();
                $this->collector->discard();
                return UserDTO::fromEntity($user); // already verified — idempotent
            }

            $this->flushEvents($user);
            $this->repository->update($user);
            $this->transaction->commit();
        } catch (\Throwable $e) {
            $this->transaction->rollback();
            $this->collector->discard();
            throw $this->wrap($e, 'user.verify_email.failed', ['id' => $id]);
        }

        $this->collector->release();
        $this->audit->record('user.email_verified', ['userId' => $id]);

        return UserDTO::fromEntity($user);
    }

    public function verifyCredentials(string $identifier, string $password): ?UserDTO
    {
        // 1. Lockout gate — refuse before any DB/hash work.
        if ($this->isLockedOut($identifier)) {
            $this->audit->record('user.login.locked_out', ['id' => self::pseudonymise($identifier)]);
            return null;
        }

        $user = $this->repository->findByIdentifier($identifier);

        // 2. Timing-safe: run a hash comparison even when the user is unknown.
        $hash = $user?->passwordHash() ?? self::DECOY_HASH;
        $ok   = $this->hasher->check($password, $hash);

        if (!$ok || $user === null || !$user->canLogin()) {
            $this->recordLoginFailure($identifier);
            $this->audit->record('user.login.failed', ['id' => self::pseudonymise($identifier)]);
            return null;
        }

        // 3. Success — clear failures and transparently upgrade the hash if the
        //    cost factor changed since it was created.
        $this->clearLoginFailures($identifier);
        if ($this->hasher->needsRehash($hash)) {
            try {
                $this->repository->persistRehash($user->id(), $this->hasher->make($password));
                $this->audit->record('user.password.rehashed', ['userId' => $user->id()]);
            } catch (\Throwable) {
                // A rehash failure must never block a valid login.
            }
        }

        return UserDTO::fromEntity($user);
    }

    public function findByIdentifier(string $identifier): ?UserDTO
    {
        if ($identifier === '') {
            return null;
        }

        $user = $this->repository->findByIdentifier($identifier);

        return $user === null ? null : UserDTO::fromEntity($user);
    }

    public function resetPassword(string $userId, string $newPassword): bool
    {
        $user = $this->repository->find($userId);
        if ($user === null) {
            return false;
        }

        $this->assertNotBreached($newPassword);

        $this->transaction->begin();
        try {
            $user->changePassword($this->hasher->make($newPassword));
            $user->commitChanges();
            $this->repository->update($user);
            // Invalidate outstanding "remember me" cookies after a reset.
            $this->repository->updateRememberToken($userId, null);
            $this->transaction->commit();
        } catch (\Throwable $e) {
            $this->transaction->rollback();
            throw $this->wrap($e, 'user.password.reset_failed', ['id' => $userId]);
        }

        $this->audit->record('user.password.reset', ['userId' => $userId]);

        return true;
    }

    public function findByRememberToken(string $token): ?UserDTO
    {
        if ($token === '') {
            return null;
        }

        $user = $this->repository->findByRememberToken(hash('sha256', $token));

        if ($user === null || !$user->canLogin()) {
            return null;
        }

        return UserDTO::fromEntity($user);
    }

    public function cycleRememberToken(string $userId): string
    {
        $plaintext = bin2hex(random_bytes(32));
        $this->repository->updateRememberToken($userId, hash('sha256', $plaintext));

        return $plaintext;
    }

    public function clearRememberToken(string $userId): void
    {
        $this->repository->updateRememberToken($userId, null);
    }

    public function delete(string $id): bool
    {
        $this->requireSelfOrPermission($id, 'user:delete-any');

        $user = $this->repository->find($id);
        if ($user === null) {
            return false;
        }

        $this->collector->beginCollection();
        $this->transaction->begin();
        try {
            $deleted = $this->repository->delete($id);
            if (!$deleted) {
                $this->transaction->rollback();
                $this->collector->discard();
                return false;
            }

            $user->markDeleted();
            $this->flushEvents($user);
            $this->transaction->commit();
        } catch (\Throwable $e) {
            $this->transaction->rollback();
            $this->collector->discard();
            throw $this->wrap($e, 'user.delete.failed', ['id' => $id]);
        }

        $this->collector->release();
        $this->audit->record('user.deleted', ['userId' => $id]);

        return true;
    }

    // ─── internals ──────────────────────────────────────────────────────────

    /**
     * Collect the entity's domain events and write their integration
     * counterparts to the outbox — all inside the active transaction.
     */
    private function flushEvents(User $user, string $originTenant = '', array $profile = []): void
    {
        foreach ($user->releaseEvents() as $event) {
            $this->collector->collect($event);

            $integration = $this->toIntegration($event, $originTenant, $profile);
            if ($integration !== null) {
                $this->outbox->write($integration);
            }
        }
    }

    private function toIntegration(DomainEventContract $event, string $originTenant = '', array $profile = []): ?IntegrationEventContract
    {
        return match (true) {
            $event instanceof UserRegisteredDomainEvent => new UserRegisteredIntegrationEvent(
                userId:     $event->userId->value(),
                username:   $event->username->value(),
                email:      $event->email->value(),
                occurredAt: $event->occurredAt->format(\DateTimeInterface::RFC3339),
                tenantId:   $originTenant,
                profile:    $profile,
            ),
            $event instanceof UserUpdatedDomainEvent => new UserUpdatedIntegrationEvent(
                userId:     $event->userId->value(),
                changed:    $event->changed,
                occurredAt: $event->occurredAt->format(\DateTimeInterface::RFC3339),
            ),
            $event instanceof UserDeletedDomainEvent => new UserDeletedIntegrationEvent(
                userId:     $event->userId->value(),
                occurredAt: $event->occurredAt->format(\DateTimeInterface::RFC3339),
            ),
            default => null,
        };
    }

    private function wrap(\Throwable $e, string $code, array $context = []): \Throwable
    {
        // Preserve typed domain/security/validation faults so the kernel maps
        // them to the right HTTP status (409/422/403) instead of a blanket 500.
        if ($e instanceof ServiceException
            || $e instanceof ValidationException
            || $e instanceof SecurityException
            || $e instanceof \AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\DomainException
            || $e instanceof \AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\OptimisticLockException
        ) {
            return $e;
        }

        return new ServiceException($code, layer: 'service.user', context: $context, previous: $e);
    }

    // ─── credential screening ────────────────────────────────────────────────

    /**
     * Reject a password known to appear in a breach corpus (NIST 800-63B).
     * No-op when screening is disabled. The checker itself fails open on a
     * provider outage, so this only ever throws on a confirmed breach hit.
     */
    private function assertNotBreached(string $plain): void
    {
        if ($this->breachChecker !== null && $this->breachChecker->isBreached($plain)) {
            throw new ValidationException([
                'password' => 'This password has appeared in a known data breach. Please choose a different one.',
            ]);
        }
    }

    // ─── authorization ──────────────────────────────────────────────────────

    private function requirePermission(string $permission): void
    {
        if (!$this->identity->hasPermission($permission)) {
            throw new SecurityException('user.forbidden', layer: 'service.user', context: ['permission' => $permission]);
        }
    }

    private function requireSelfOrPermission(string $targetUserId, string $permission): void
    {
        if ($this->identity->isGuest()) {
            throw new SecurityException('user.unauthenticated', layer: 'service.user');
        }
        if (hash_equals($this->identity->userId, $targetUserId)) {
            return;
        }
        $this->requirePermission($permission);
    }

    // ─── lockout (CachePort) ─────────────────────────────────────────────────

    private function isLockedOut(string $identifier): bool
    {
        return (int) ($this->cache->get($this->lockKey($identifier)) ?? 0) >= self::MAX_LOGIN_FAILURES;
    }

    private function recordLoginFailure(string $identifier): void
    {
        $key = $this->lockKey($identifier);
        if (!$this->cache->has($key)) {
            $this->cache->set($key, 0, self::LOCKOUT_WINDOW);
        }
        $this->cache->increment($key);
    }

    private function clearLoginFailures(string $identifier): void
    {
        $this->cache->delete($this->lockKey($identifier));
    }

    private function lockKey(string $identifier): string
    {
        // Hash the identifier (PII). Identity is global (central users table).
        return 'user:login:fail:' . hash('sha256', mb_strtolower($identifier));
    }

    /** Short, non-reversible tag for audit logs (no raw email/username). */
    private static function pseudonymise(string $identifier): string
    {
        return substr(hash('sha256', mb_strtolower($identifier)), 0, 12);
    }
}
