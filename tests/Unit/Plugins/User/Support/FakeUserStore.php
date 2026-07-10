<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\User\Support;

use Plugins\User\API\DTOs\ListUsersQuery;
use Plugins\User\Application\Ports\UserStore;
use Plugins\User\Domain\Entities\User;
use Plugins\User\Domain\Exceptions\DuplicateUserException;

/** In-memory UserStore for service unit tests. */
final class FakeUserStore implements UserStore
{
    /** @var array<string, User> keyed by user_id */
    public array $byId = [];
    /** @var array<string,string> */
    public array $rehashed = [];

    public function paginate(ListUsersQuery $query): array
    {
        $all = array_values($this->byId);
        $hasMore = count($all) > $query->limit;
        return [array_slice($all, 0, $query->limit), $hasMore];
    }

    public function find(string $userId): ?User
    {
        return $this->byId[$userId] ?? null;
    }

    public function findByIdentifier(string $identifier): ?User
    {
        foreach ($this->byId as $u) {
            if ($u->username() === $identifier
                || $u->email() === mb_strtolower($identifier)) {
                return $u;
            }
        }
        return null;
    }

    /** @var array<string,string> userId => remember-token hash */
    public array $rememberTokens = [];

    public function findByRememberToken(string $tokenHash): ?User
    {
        if ($tokenHash === '') {
            return null;
        }
        foreach ($this->rememberTokens as $userId => $hash) {
            if (hash_equals($hash, $tokenHash)) {
                return $this->byId[$userId] ?? null;
            }
        }
        return null;
    }

    public function findByVerificationTokenHash(string $tokenHash): ?User
    {
        if ($tokenHash === '') {
            return null;
        }
        foreach ($this->byId as $user) {
            if (hash_equals((string) $user->emailVerificationTokenHash(), $tokenHash)) {
                return $user;
            }
        }
        return null;
    }

    public function updateRememberToken(string $userId, ?string $tokenHash): void
    {
        if ($tokenHash === null) {
            unset($this->rememberTokens[$userId]);
            return;
        }
        $this->rememberTokens[$userId] = $tokenHash;
    }

    public function existsByUsernameOrEmail(string $username, string $email, ?string $exceptUserId = null): bool
    {
        foreach ($this->byId as $u) {
            if ($u->id() === $exceptUserId) {
                continue;
            }
            if ($u->username() === $username || $u->email() === $email) {
                return true;
            }
        }
        return false;
    }

    public function insert(User $user): void
    {
        if ($this->existsByUsernameOrEmail($user->username(), $user->email())) {
            throw new DuplicateUserException();
        }
        $this->byId[$user->id()] = $user;
    }

    public function update(User $user): void
    {
        $this->byId[$user->id()] = $user;
    }

    public function persistRehash(string $userId, string $passwordHash): void
    {
        $this->rehashed[$userId] = $passwordHash;
    }

    public function delete(string $userId): bool
    {
        if (!isset($this->byId[$userId])) {
            return false;
        }
        unset($this->byId[$userId]);
        return true;
    }
}
