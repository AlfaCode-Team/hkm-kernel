<?php

declare(strict_types=1);

namespace Plugins\Auth\Infrastructure\Persistence;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\RepositoryException;
use Plugins\Auth\Domain\Entities\PersonalAccessToken;

/**
 * Persists personal access tokens (hashed) via DatabasePort only.
 *
 * Table: personal_access_tokens(id, user_id, name, token_hash, last_used_at, created_at)
 */
final class PersonalAccessTokenRepository
{
    public function __construct(
        private readonly DatabasePort $db,
        private readonly string $table = 'personal_access_tokens',
        private readonly string $usersTable = 'users',
    ) {
    }

    /**
     * @param list<string> $abilities Scope list; null/[] = no abilities granted.
     * @param \DateTimeImmutable|null $expiresAt Absolute expiry; null = never expires.
     */
    public function store(
        string $id,
        string $userId,
        string $name,
        string $tokenHash,
        array $abilities = [],
        ?\DateTimeImmutable $expiresAt = null,
    ): void {
        $token = PersonalAccessToken::issue($id, $userId, $name, $tokenHash, $abilities, $expiresAt);

        try {
            $this->db->execute(
                "INSERT INTO {$this->table} (id, user_id, name, token_hash, abilities, expires_at, created_at)
                 VALUES (:id, :user_id, :name, :token_hash, :abilities, :expires_at, :created_at)",
                [
                    'id'         => $token->id(),
                    'user_id'    => $token->userId(),
                    'name'       => $token->name(),
                    'token_hash' => $token->tokenHash(),
                    'abilities'  => $token->abilitiesColumn(),
                    'expires_at' => $token->expiresAt()?->format('Y-m-d H:i:s'),
                    'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                ]
            );
        } catch (\PDOException $e) {
            throw new RepositoryException('Failed to store access token', layer: 'repository.auth', previous: $e);
        }
    }

    /**
     * Look up an UNEXPIRED token by its hash. Expired tokens are treated as
     * absent so a stale credential can never authenticate.
     *
     * The owning user's display identity (username/email from the central
     * `users` table) rides on the same query via a LEFT JOIN, so the security
     * layer can build a full Identity without touching the DatabasePort itself.
     * A missing user row degrades to '' — display data never gates auth.
     *
     * @return array{id:string,user_id:string,abilities:list<string>,username:string,email:string}|null
     */
    public function findByHash(string $tokenHash): ?array
    {
        try {
            $row = $this->db->queryOne(
                "SELECT t.id, t.user_id, t.abilities, t.expires_at,
                        u.username AS owner_username, u.email AS owner_email
                 FROM {$this->table} t
                 LEFT JOIN {$this->usersTable} u ON u.user_id = t.user_id
                 WHERE t.token_hash = :hash",
                ['hash' => $tokenHash]
            );
        } catch (\PDOException $e) {
            throw new RepositoryException('Failed to look up access token', layer: 'repository.auth', previous: $e);
        }

        if ($row === null) {
            return null;
        }

        $username = (string) ($row['owner_username'] ?? '');
        $email    = (string) ($row['owner_email'] ?? '');
        unset($row['owner_username'], $row['owner_email']);

        $token = PersonalAccessToken::reconstitute($row);

        // Enforce expiry in PHP (driver-portable — no NOW() dialect branching).
        if ($token->isExpired()) {
            return null;
        }

        return [
            'id'        => $token->id(),
            'user_id'   => $token->userId(),
            'abilities' => $token->abilities(),
            'username'  => $username,
            'email'     => $email,
        ];
    }

    /**
     * List every token issued to a user (newest first), WITHOUT the hash. Feeds
     * AuthServiceContract::tokensFor() — the GDA replacement for HasApiTokens.
     *
     * @return list<array{id:string,name:string,abilities:mixed,expires_at:?string,last_used_at:?string,created_at:?string}>
     */
    public function findByUser(string $userId): array
    {
        try {
            $rows = $this->db->query(
                "SELECT id, name, abilities, expires_at, last_used_at, created_at
                 FROM {$this->table} WHERE user_id = :user_id ORDER BY created_at DESC",
                ['user_id' => $userId]
            );
        } catch (\PDOException $e) {
            throw new RepositoryException('Failed to list access tokens', layer: 'repository.auth', previous: $e);
        }

        return array_values($rows);
    }

    /** Stamp the token's last-use time (best-effort observability/anomaly detection). */
    public function touch(string $id): void
    {
        try {
            $this->db->execute(
                "UPDATE {$this->table} SET last_used_at = :now WHERE id = :id",
                ['now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'), 'id' => $id]
            );
        } catch (\PDOException) {
            // Non-fatal — last_used_at is observability metadata, not an auth gate.
        }
    }

    public function delete(string $id): void
    {
        try {
            $this->db->execute("DELETE FROM {$this->table} WHERE id = :id", ['id' => $id]);
        } catch (\PDOException $e) {
            throw new RepositoryException('Failed to revoke access token', layer: 'repository.auth', previous: $e);
        }
    }

    /**
     * Delete every token whose absolute expiry has already passed. Returns the
     * number of rows removed. Driver-portable — binds the cutoff rather than
     * relying on a dialect-specific NOW().
     */
    public function deleteExpired(?\DateTimeImmutable $now = null): int
    {
        $cutoff = ($now ?? new \DateTimeImmutable())->format('Y-m-d H:i:s');

        try {
            return $this->db->execute(
                "DELETE FROM {$this->table} WHERE expires_at IS NOT NULL AND expires_at <= :cutoff",
                ['cutoff' => $cutoff]
            );
        } catch (\PDOException $e) {
            throw new RepositoryException('Failed to prune expired access tokens', layer: 'repository.auth', previous: $e);
        }
    }

    /** Count tokens whose expiry has passed (drives the prune command's --dry mode). */
    public function countExpired(?\DateTimeImmutable $now = null): int
    {
        $cutoff = ($now ?? new \DateTimeImmutable())->format('Y-m-d H:i:s');

        try {
            $row = $this->db->queryOne(
                "SELECT COUNT(*) AS n FROM {$this->table} WHERE expires_at IS NOT NULL AND expires_at <= :cutoff",
                ['cutoff' => $cutoff]
            );
        } catch (\PDOException $e) {
            throw new RepositoryException('Failed to count expired access tokens', layer: 'repository.auth', previous: $e);
        }

        return (int) ($row['n'] ?? 0);
    }
}
