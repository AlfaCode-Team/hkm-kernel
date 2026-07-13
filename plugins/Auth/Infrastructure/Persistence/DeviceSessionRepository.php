<?php

declare(strict_types=1);

namespace Plugins\Auth\Infrastructure\Persistence;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\RepositoryException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;

/**
 * Persists server-side device sessions (hashed tokens) via DatabasePort only.
 *
 * Table: auth_sessions(session_id, user_id, token_hash, fingerprint, ip,
 *        user_agent, last_seen_at, expires_at, revoked_at, created_at)
 */
final class DeviceSessionRepository
{
    public function __construct(
        private readonly DatabasePort $db,
        private readonly string $table = 'auth_sessions',
    ) {
    }

    public function insert(
        string $sessionId,
        string $userId,
        string $tokenHash,
        ?string $fingerprint,
        ?string $ip,
        ?string $userAgent,
        \DateTimeImmutable $expiresAt,
    ): void {
        try {
            $this->db->execute(
                "INSERT INTO {$this->table}
                    (session_id, user_id, token_hash, fingerprint, ip, user_agent, last_seen_at, expires_at, created_at)
                 VALUES (:session_id, :user_id, :token_hash, :fingerprint, :ip, :user_agent, :last_seen_at, :expires_at, :created_at)",
                [
                    'session_id'   => $sessionId,
                    'user_id'      => $userId,
                    'token_hash'   => $tokenHash,
                    'fingerprint'  => $fingerprint,
                    'ip'           => $ip !== null ? mb_substr($ip, 0, 45) : null,
                    'user_agent'   => $userAgent !== null ? mb_substr($userAgent, 0, 191) : null,
                    'last_seen_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                    'expires_at'   => $expiresAt->format('Y-m-d H:i:s'),
                    'created_at'   => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                ]
            );
        } catch (\PDOException $e) {
            throw new RepositoryException('Failed to open device session', layer: 'repository.auth', previous: $e);
        }
    }

    /**
     * Look up an UNREVOKED session by its token hash. Expiry is enforced in PHP
     * by the caller (driver-portable — no NOW() dialect branching).
     *
     * @return array{session_id:string,user_id:string,fingerprint:?string,last_seen_at:?string,expires_at:string}|null
     */
    public function findActiveByHash(string $tokenHash): ?array
    {
        try {
            return $this->db->queryOne(
                "SELECT session_id, user_id, fingerprint, last_seen_at, expires_at
                 FROM {$this->table} WHERE token_hash = :hash AND revoked_at IS NULL",
                ['hash' => $tokenHash]
            );
        } catch (\PDOException $e) {
            throw new RepositoryException('Failed to look up device session', layer: 'repository.auth', previous: $e);
        }
    }

    /**
     * Stamp last-seen and (for rolling refresh) push the expiry forward.
     * Best-effort — observability + sliding lifetime, not an auth gate.
     */
    public function touch(string $sessionId, ?\DateTimeImmutable $newExpiresAt = null): void
    {
        $params = [
            'now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'id'  => $sessionId,
        ];
        $set = 'last_seen_at = :now';

        if ($newExpiresAt !== null) {
            $set .= ', expires_at = :expires_at';
            $params['expires_at'] = $newExpiresAt->format('Y-m-d H:i:s');
        }

        try {
            $this->db->execute("UPDATE {$this->table} SET {$set} WHERE session_id = :id", $params);
        } catch (\PDOException) {
            // Non-fatal.
        }
    }

    public function revokeByHash(string $tokenHash): void
    {
        try {
            $this->db->execute(
                "UPDATE {$this->table} SET revoked_at = :now WHERE token_hash = :hash AND revoked_at IS NULL",
                ['now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'), 'hash' => $tokenHash]
            );
        } catch (\PDOException $e) {
            throw new RepositoryException('Failed to revoke device session', layer: 'repository.auth', previous: $e);
        }
    }

    /** Revoke one of a user's sessions by its public id. True when a row changed. */
    public function revokeForUser(string $userId, string $sessionId): bool
    {
        try {
            return $this->db->execute(
                "UPDATE {$this->table} SET revoked_at = :now
                 WHERE user_id = :user_id AND session_id = :id AND revoked_at IS NULL",
                [
                    'now'     => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                    'user_id' => $userId,
                    'id'      => $sessionId,
                ]
            ) > 0;
        } catch (\PDOException $e) {
            throw new RepositoryException('Failed to revoke device session', layer: 'repository.auth', previous: $e);
        }
    }

    /** Revoke every active session for a user, optionally sparing one (the current device). */
    public function revokeAllForUser(string $userId, ?string $exceptSessionId = null): int
    {
        $sql    = "UPDATE {$this->table} SET revoked_at = :now WHERE user_id = :user_id AND revoked_at IS NULL";
        $params = [
            'now'     => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'user_id' => $userId,
        ];

        if ($exceptSessionId !== null) {
            $sql .= ' AND session_id <> :except';
            $params['except'] = $exceptSessionId;
        }

        try {
            return $this->db->execute($sql, $params);
        } catch (\PDOException $e) {
            throw new RepositoryException('Failed to revoke device sessions', layer: 'repository.auth', previous: $e);
        }
    }

    /**
     * All active (unrevoked, unexpired) sessions for a user, newest first.
     * Never returns the token hash.
     *
     * @return list<array{session_id:string,ip:?string,user_agent:?string,last_seen_at:?string,created_at:?string,expires_at:string}>
     */
    public function listActiveForUser(string $userId): array
    {
        try {
            $rows = $this->db->query(
                "SELECT session_id, ip, user_agent, last_seen_at, created_at, expires_at
                 FROM {$this->table}
                 WHERE user_id = :user_id AND revoked_at IS NULL AND expires_at > :cutoff
                 ORDER BY created_at DESC",
                [
                    'user_id' => $userId,
                    'cutoff'  => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                ]
            );
        } catch (\PDOException $e) {
            throw new RepositoryException('Failed to list device sessions', layer: 'repository.auth', previous: $e);
        }

        return array_values($rows);
    }

    /** Delete expired/revoked rows older than the cutoff (maintenance). */
    public function deleteStale(?\DateTimeImmutable $now = null): int
    {
        $cutoff = ($now ?? new \DateTimeImmutable())->format('Y-m-d H:i:s');

        try {
            return $this->db->execute(
                "DELETE FROM {$this->table} WHERE expires_at <= :cutoff OR revoked_at IS NOT NULL",
                ['cutoff' => $cutoff]
            );
        } catch (\PDOException $e) {
            throw new RepositoryException('Failed to prune device sessions', layer: 'repository.auth', previous: $e);
        }
    }
}
