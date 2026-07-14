<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Auth\Support;

use Plugins\User\API\Contracts\UserServiceContract;
use Plugins\User\API\DTOs\ListUsersQuery;
use Plugins\User\API\DTOs\VerifyEmailResult;
use Plugins\User\API\DTOs\RegisterUserDTO;
use Plugins\User\API\DTOs\UpdateUserDTO;
use Plugins\User\API\DTOs\UserDTO;
use Plugins\User\API\DTOs\UserPage;
use Plugins\User\API\DTOs\VerifyEmailDTO;

/**
 * Minimal UserServiceContract double for AuthManager/provider tests. Only the
 * lookup methods the auth stack uses are functional; the rest throw.
 */
final class FakeUserService implements UserServiceContract
{
    /** @var array<string,UserDTO> id => user */
    public array $byId = [];
    /** @var array<string,string> identifier => id (for verifyCredentials) */
    public array $credentials = [];
    /** @var array<string,string> rememberToken => id */
    public array $rememberTokens = [];

    public function seed(string $id, string $username, string $email): UserDTO
    {
        $dto = new UserDTO($id, $username, $email, true, '2026-01-01T00:00:00+00:00');
        $this->byId[$id] = $dto;
        return $dto;
    }

    /** @var list<array{string, bool}> recorded find() calls: [id, checkMembership] */
    public array $findCalls = [];

    /** @var list<string> ids treated as having NO seat when membership is checked */
    public array $nonMembers = [];

    public function find(string $id, bool $checkMembership = false, bool $isAuth = false): ?UserDTO
    {
        $this->findCalls[] = [$id, $checkMembership];
        if ($checkMembership && \in_array($id, $this->nonMembers, true)) {
            return null;
        }
        return $this->byId[$id] ?? null;
    }

    public function verifyCredentials(string $identifier, string $password): ?UserDTO
    {
        $id = $this->credentials[$identifier . ':' . $password] ?? null;
        return $id === null ? null : ($this->byId[$id] ?? null);
    }

    public function findByRememberToken(string $token): ?UserDTO
    {
        $id = $this->rememberTokens[$token] ?? null;
        return $id === null ? null : ($this->byId[$id] ?? null);
    }

    /** @var array<string,string> id => plaintext password set by resetPassword */
    public array $resetPasswords = [];

    public function findByIdentifier(string $identifier, bool $checkMembership = false): ?UserDTO
    {
        foreach ($this->byId as $u) {
            if ($u->username === $identifier || $u->email === $identifier) {
                return $u;
            }
        }
        return null;
    }

    public function resetPassword(string $userId, string $newPassword): bool
    {
        if (!isset($this->byId[$userId])) {
            return false;
        }
        $this->resetPasswords[$userId] = $newPassword;
        return true;
    }

    public function cycleRememberToken(string $userId, bool $checkMembership = false): string { return 'rotated'; }
    public function clearRememberToken(string $userId, bool $checkMembership = false): void {}

    public function list(ListUsersQuery $query): UserPage { throw new \BadMethodCallException(); }
    public function register(RegisterUserDTO $dto): UserDTO { throw new \BadMethodCallException(); }
    public function registerPublic(RegisterUserDTO $dto): string { throw new \BadMethodCallException(); }
    public function verifyEmailByToken(string $token): VerifyEmailResult { throw new \BadMethodCallException(); }
    public function resendVerification(string $email): ?string { throw new \BadMethodCallException(); }
    public function update(string $id, UpdateUserDTO $dto): ?UserDTO { throw new \BadMethodCallException(); }
    public function verifyEmail(string $id, VerifyEmailDTO $dto): ?UserDTO { throw new \BadMethodCallException(); }
    public function delete(string $id, bool $checkMembership = false): bool { throw new \BadMethodCallException(); }
}
