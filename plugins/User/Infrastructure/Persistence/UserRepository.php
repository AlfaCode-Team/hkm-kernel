<?php

declare(strict_types=1);

namespace Plugins\User\Infrastructure\Persistence;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\OptimisticLockException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\RepositoryException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use Plugins\User\API\DTOs\ListUsersQuery;
use Plugins\User\Application\Ports\UserStore;
use Plugins\User\Domain\Entities\User;
use Plugins\User\Domain\Exceptions\DuplicateUserException;

/**
 * UserRepository — DatabasePort ONLY. The `users` table is owned by the plugin
 * migration; this class never creates or alters schema.
 *
 * `users` is the GLOBAL central identity table: identity is centralized and
 * username/email are globally unique. The injected DatabasePort is the CENTRAL
 * connection (pinned by the Provider via the ConnectionManager default) so
 * identity reads/writes always target the central database.
 *
 * Invariants:
 *   - Every query is parameterised (no interpolation).
 *   - Reads exclude soft-deleted rows (deleted_at IS NULL).
 *   - Writes are optimistic-locked on `version`; a stale write throws
 *     OptimisticLockException (→ HTTP 409) instead of silently clobbering.
 *   - Exception context carries IDs only — never raw email/username (no PII in
 *     logs).
 */
final class UserRepository implements UserStore
{
    private const TABLE = 'users';

    private const COLUMNS =
        'user_id, username, email, password_hash, remember_token,
         version, email_verified_at, email_verification_token_hash,
         email_verification_expires_at, created_at';

    public function __construct(
        private readonly DatabasePort $db,
    ) {}

    /**
     * Keyset-paginated listing (stable, O(1) deep pages). Fetches one extra row
     * to compute hasMore without a COUNT.
     *
     * @return array{0: list<User>, 1: bool} [users, hasMore]
     */
    public function paginate(ListUsersQuery $query): array
    {
        // Inline LIMIT as a validated int: bound params bind as strings and
        // native prepares (EMULATE_PREPARES=false) reject `LIMIT '100'`.
        $limit  = max(1, min(1001, $query->limit + 1));
        $params = [];
        $cursor = '';
        if ($query->after !== null) {
            // user_id DESC → fetch rows strictly "older" than the cursor.
            $cursor = ' AND user_id < :after';
            $params['after'] = $query->after;
        }

        try {
            $rows = $this->db->query(
                'SELECT ' . self::COLUMNS . ' FROM ' . self::TABLE . '
                 WHERE deleted_at IS NULL' . $cursor . '
                 ORDER BY user_id DESC
                 LIMIT ' . $limit,
                $params,
            );
        } catch (\Throwable $e) {
            throw new RepositoryException('Failed to list users.', layer: 'repository.user', previous: $e);
        }

        $hasMore = count($rows) > $query->limit;
        if ($hasMore) {
            array_pop($rows);
        }

        return [array_map(static fn(array $r): User => self::hydrate($r), $rows), $hasMore];
    }

    public function find(string $userId): ?User
    {
        $row = $this->fetchBy('user_id', $userId);
        return $row === null ? null : self::hydrate($row);
    }

    /** Look up an active user by the SHA-256 hash of a "remember me" token. */
    public function findByRememberToken(string $tokenHash): ?User
    {
        // An empty hash must never match — guard so a NULL/blank column can't
        // authenticate a forged empty cookie.
        if ($tokenHash === '') {
            return null;
        }

        $row = $this->fetchBy('remember_token', $tokenHash);

        return $row === null ? null : self::hydrate($row);
    }

    /**
     * Resolve a user by the SHA-256 hash of a pending email-verification token.
     * Expiry is checked in the service (it holds the clock); an empty hash never
     * matches so a blank/NULL column cannot confirm a forged empty token.
     */
    public function findByVerificationTokenHash(string $tokenHash): ?User
    {
        if ($tokenHash === '') {
            return null;
        }

        $row = $this->fetchBy('email_verification_token_hash', $tokenHash);

        return $row === null ? null : self::hydrate($row);
    }

    /** Persist (or clear, with null) the remember-token hash for a user. */
    public function updateRememberToken(string $userId, ?string $tokenHash): void
    {
        try {
            $this->db->execute(
                'UPDATE ' . self::TABLE . '
                 SET remember_token = :token, updated_at = :now
                 WHERE user_id = :user_id AND deleted_at IS NULL',
                ['token' => $tokenHash, 'now' => self::now(), 'user_id' => $userId],
            );
        } catch (\Throwable $e) {
            throw new RepositoryException('Failed to update remember token.', layer: 'repository.user', previous: $e);
        }
    }

    /** Look up by username OR email — used for credential verification/login. */
    public function findByIdentifier(string $identifier): ?User
    {
        try {
            $row = $this->db->queryOne(
                'SELECT ' . self::COLUMNS . ' FROM ' . self::TABLE . '
                 WHERE (username = :id OR email = :email)
                   AND deleted_at IS NULL
                 LIMIT 1',
                ['id' => $identifier, 'email' => mb_strtolower($identifier)],
            );
        } catch (\Throwable $e) {
            throw new RepositoryException('Failed to load user by identifier.', layer: 'repository.user', previous: $e);
        }

        return $row === null ? null : self::hydrate($row);
    }

    public function existsByUsernameOrEmail(string $username, string $email, ?string $exceptUserId = null): bool
    {
        $sql = 'SELECT 1 AS hit FROM ' . self::TABLE . '
                WHERE (username = :username OR email = :email)
                  AND deleted_at IS NULL';
        $params = ['username' => $username, 'email' => $email];

        if ($exceptUserId !== null) {
            $sql .= ' AND user_id <> :except';
            $params['except'] = $exceptUserId;
        }

        try {
            $row = $this->db->queryOne($sql . ' LIMIT 1', $params);
        } catch (\Throwable $e) {
            throw new RepositoryException('Failed to check user uniqueness.', layer: 'repository.user', previous: $e);
        }

        return $row !== null;
    }

    public function insert(User &$user): void
    {
        // The entity is the source of truth for created_at (set at register()).
        // updated_at equals it on first insert.
        $createdAt = $user->createdAt();
        $updatedAt = $createdAt;

        try {
            $this->db->execute(
                'INSERT INTO ' . self::TABLE . '
                    (user_id, username, email, password_hash, remember_token,
                     version, email_verified_at, email_verification_token_hash,
                     email_verification_expires_at, created_at, updated_at)
                 VALUES
                    (:user_id, :username, :email, :password_hash, :remember_token,
                     :version, :email_verified_at, :verif_token, :verif_expires,
                     :created_at, :updated_at)',
                [
                    'user_id'           => $user->id(),
                    'username'          => $user->username(),
                    'email'             => $user->email(),
                    'password_hash'     => $user->passwordHash(),
                    'remember_token'    => $user->rememberToken(),
                    'version'           => $user->version(),
                    'email_verified_at' => self::fmt($user->emailVerifiedAt()),
                    'verif_token'       => $user->emailVerificationTokenHash(),
                    'verif_expires'     => self::fmt($user->emailVerificationExpiresAt()),
                    'created_at'        => self::fmt($createdAt),
                    'updated_at'        => self::fmt($updatedAt),
                ],
            );
        } catch (\Throwable $e) {
            if (self::isUniqueViolation($e)) {
                throw new DuplicateUserException();
            }
            throw new RepositoryException(
                'Failed to insert user.',
                layer: 'repository.user',
                context: ['userId' => $user->id()],
                previous: $e,
            );
        }

        // Reflect the persisted timestamps back onto the caller's entity, then
        // mark it clean so it reports no pending changes after the write.
        $user->setAttribute('updated_at', $updatedAt);
        $user->syncOriginal();
    }

    /**
     * Optimistic-locked update. The entity has already bumped its version (via
     * commitChanges); we write WHERE version = newVersion - 1 and require one
     * affected row.
     */
    public function update(User $user): void
    {
        $expected = $user->version() - 1;

        try {
            $affected = $this->db->execute(
                'UPDATE ' . self::TABLE . ' SET
                    username          = :username,
                    email             = :email,
                    password_hash     = :password_hash,
                    remember_token    = :remember_token,
                    email_verified_at = :email_verified_at,
                    email_verification_token_hash = :verif_token,
                    email_verification_expires_at = :verif_expires,
                    version           = :version,
                    updated_at        = :updated_at
                 WHERE user_id = :user_id
                   AND version = :expected AND deleted_at IS NULL',
                [
                    'username'          => $user->username(),
                    'email'             => $user->email(),
                    'password_hash'     => $user->passwordHash(),
                    'remember_token'    => $user->rememberToken(),
                    'email_verified_at' => self::fmt($user->emailVerifiedAt()),
                    'verif_token'       => $user->emailVerificationTokenHash(),
                    'verif_expires'     => self::fmt($user->emailVerificationExpiresAt()),
                    'version'           => $user->version(),
                    'updated_at'        => self::now(),
                    'user_id'           => $user->id(),
                    'expected'          => $expected,
                ],
            );
        } catch (\Throwable $e) {
            if (self::isUniqueViolation($e)) {
                throw new DuplicateUserException();
            }
            throw new RepositoryException(
                'Failed to update user.',
                layer: 'repository.user',
                context: ['userId' => $user->id()],
                previous: $e,
            );
        }
           

        if ($affected < 1) {
            throw new OptimisticLockException(
                'User was modified concurrently; reload and retry.',
                layer: 'repository.user',
                context: ['userId' => $user->id(), 'expectedVersion' => $expected],
            );
        }
    }

    /**
     * Persist only a re-hashed password (rehash-on-login). No version bump, no
     * events — it is a transparent credential upgrade, not a domain change.
     */
    public function persistRehash(string $userId, string $passwordHash): void
    {
        try {
            $this->db->execute(
                'UPDATE ' . self::TABLE . ' SET password_hash = :hash
                 WHERE user_id = :user_id AND deleted_at IS NULL',
                ['hash' => $passwordHash, 'user_id' => $userId],
            );
        } catch (\Throwable $e) {
            throw new RepositoryException(
                'Failed to upgrade password hash.',
                layer: 'repository.user',
                context: ['userId' => $userId],
                previous: $e,
            );
        }
    }

    /** Soft delete — sets deleted_at, never removes the row. */
    public function delete(string $userId): bool
    {
        $now = self::now();

        try {
            $affected = $this->db->execute(
                'UPDATE ' . self::TABLE . '
                 SET deleted_at = :now, updated_at = :now
                 WHERE user_id = :user_id AND deleted_at IS NULL',
                ['now' => $now, 'user_id' => $userId],
            );
        } catch (\Throwable $e) {
            throw new RepositoryException(
                'Failed to delete user.',
                layer: 'repository.user',
                context: ['userId' => $userId],
                previous: $e,
            );
        }

        return $affected > 0;
    }

    /** @return array<string, mixed>|null */
    private function fetchBy(string $column, string $value): ?array
    {
        try {
            return $this->db->queryOne(
                'SELECT ' . self::COLUMNS . ' FROM ' . self::TABLE . '
                 WHERE ' . $column . ' = :value AND deleted_at IS NULL
                 LIMIT 1',
                ['value' => $value],
            );
        } catch (\Throwable $e) {
            // NB: no PII (the looked-up value) in the log context — only the column.
            throw new RepositoryException(
                'Failed to load user.',
                layer: 'repository.user',
                context: ['by' => $column],
                previous: $e,
            );
        }
    }

    /** @param array<string, mixed> $row */
    private static function hydrate(array $row): User
    {
        // The Entity base hydrates from the raw row keyed by column name and
        // records no events; casts apply lazily on read.
        return User::reconstitute($row);
    }

    private static function now(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d H:i:s');
    }

    private static function fmt(?\DateTimeImmutable $dt): ?string
    {
        return $dt?->format('Y-m-d H:i:s');
    }

    /** Detect a UNIQUE/duplicate-key violation across PDO drivers. */
    private static function isUniqueViolation(\Throwable $e): bool
    {
        for ($cur = $e; $cur !== null; $cur = $cur->getPrevious()) {
            if ($cur instanceof \PDOException) {
                // SQLSTATE 23000/23505 = integrity constraint / unique violation.
                $sqlState = is_array($cur->errorInfo ?? null) ? ($cur->errorInfo[0] ?? '') : (string) $cur->getCode();
                if (in_array($sqlState, ['23000', '23505'], true)) {
                    return true;
                }
            }
            $msg = strtolower($cur->getMessage());
            if (str_contains($msg, 'duplicate') || str_contains($msg, 'unique constraint')) {
                return true;
            }
        }
        return false;
    }
}
