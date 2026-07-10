<?php

declare(strict_types=1);

namespace Plugins\User\API\Contracts;

use Plugins\User\API\DTOs\ListUsersQuery;
use Plugins\User\API\DTOs\RegisterUserDTO;
use Plugins\User\API\DTOs\UpdateUserDTO;
use Plugins\User\API\DTOs\UserDTO;
use Plugins\User\API\DTOs\UserPage;
use Plugins\User\API\DTOs\VerifyEmailDTO;

/**
 * Published contract for the user.management domain. Other modules depend on
 * THIS — never on the concrete service, repository, or entities.
 */
interface UserServiceContract
{
    /** Keyset-paginated listing (admin-only). */
    public function list(ListUsersQuery $query): UserPage;

    /** Admin/back-office registration — returns the full record for display. */
    public function register(RegisterUserDTO $dto): UserDTO;

    /**
     * Public self-signup. Returns ONLY the plaintext verification token for the
     * caller to email — never the identity record, so a public registrant learns
     * nothing about the created account beyond "check your inbox".
     */
    public function registerPublic(RegisterUserDTO $dto): string;

    /**
     * Confirm an email from the PUBLIC (unauthenticated) verification link. The
     * token is matched by its stored hash and must be unexpired + unconsumed.
     * Returns false on any miss. No identity required — this is the pre-login flow.
     */
    public function verifyEmailByToken(string $token): bool;

    public function find(string $id): ?UserDTO;

    /** Look up a user by username OR email (no credential check). Null if absent. */
    public function findByIdentifier(string $identifier): ?UserDTO;

    /**
     * Force-set a user's password (password-reset flow — token-authorized, so it
     * bypasses the self/permission gate). Also clears remember tokens so existing
     * "remember me" cookies die. Returns false if no such user.
     */
    public function resetPassword(string $userId, string $newPassword): bool;

    /** Apply a partial update. Returns null if no such (non-deleted) user. */
    public function update(string $id, UpdateUserDTO $dto): ?UserDTO;

    /** Confirm a user's email from a verification token. Null if no such user. */
    public function verifyEmail(string $id, VerifyEmailDTO $dto): ?UserDTO;

    /**
     * Verify a plaintext credential for a username/email. Returns the user on
     * success, null on any failure (unknown user, wrong password, inactive,
     * or temporarily locked out). Timing-safe and rate-limited.
     */
    public function verifyCredentials(string $identifier, string $password): ?UserDTO;

    /**
     * Resolve a user from a plaintext "remember me" token (the second segment of
     * a recaller cookie). Returns null on any miss so a forged/stale token can
     * never authenticate. Timing-safe: the token is matched by its stored hash.
     */
    public function findByRememberToken(string $token): ?UserDTO;

    /**
     * Issue a fresh remember-token for a user, persist its hash, and return the
     * PLAINTEXT once (goes into the recaller cookie). Rotating on every use means
     * a stolen cookie is invalidated the moment the real user next authenticates.
     */
    public function cycleRememberToken(string $userId): string;

    /** Clear a user's remember-token (logout) so outstanding recaller cookies die. */
    public function clearRememberToken(string $userId): void;

    public function delete(string $id): bool;
}
