<?php

declare(strict_types=1);

namespace Plugins\User\Application\Ports;

use Plugins\User\API\DTOs\ListUsersQuery;
use Plugins\User\Domain\Entities\User;

/**
 * Internal persistence port for the user.management domain (DIP seam).
 *
 * The Application service depends on THIS, not the concrete repository, so it
 * can be unit-tested with an in-memory fake. Not part of the public API —
 * other modules use UserServiceContract.
 */
interface UserStore
{
    /**
     * @return array{0: list<User>, 1: bool} [users, hasMore]
     */
    public function paginate(ListUsersQuery $query): array;

    public function find(string $userId): ?User;

    public function findByIdentifier(string $identifier): ?User;

    /** Look up an active user by the SHA-256 hash of a "remember me" token. */
    public function findByRememberToken(string $tokenHash): ?User;

    /** Look up an active user by the SHA-256 hash of a pending verification token. */
    public function findByVerificationTokenHash(string $tokenHash): ?User;

    /** Persist (or clear, with null) the remember-token hash for a user. */
    public function updateRememberToken(string $userId, ?string $tokenHash): void;

    public function existsByUsernameOrEmail(string $username, string $email, ?string $exceptUserId = null): bool;

    /**
     * Persist a new user. Passed by reference so the store can reflect the
     * persisted timestamps (created_at / updated_at) back onto the entity,
     * leaving the caller with a fully-synced aggregate.
     */
    public function insert(User &$user): void;

    public function update(User $user): void;

    public function persistRehash(string $userId, string $passwordHash): void;

    public function delete(string $userId): bool;
}
