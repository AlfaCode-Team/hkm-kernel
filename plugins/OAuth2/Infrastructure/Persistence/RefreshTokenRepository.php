<?php

declare(strict_types=1);

namespace Plugins\OAuth2\Infrastructure\Persistence;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\RepositoryException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use Plugins\OAuth2\Application\Ports\RefreshTokenStore;
use Plugins\OAuth2\Domain\Entities\RefreshToken;

final class RefreshTokenRepository implements RefreshTokenStore
{
    public function __construct(private readonly DatabasePort $db)
    {
    }

    public function store(RefreshToken $token, string $tokenHash): void
    {
        try {
            $this->db->execute(
                'INSERT INTO oauth_refresh_tokens
                    (id, family_id, token_hash, client_id, user_id, scopes, revoked, expires_at, created_at)
                 VALUES (:id, :family, :hash, :client, :user, :scopes, 0, :expires, :created)',
                [
                    'id'      => $token->id,
                    'family'  => $token->familyId,
                    'hash'    => $tokenHash,
                    'client'  => $token->clientId,
                    'user'    => $token->userId,
                    'scopes'  => json_encode(array_values($token->scopes)),
                    'expires' => $token->expiresAt->format('Y-m-d H:i:s'),
                    'created' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                ],
            );
        } catch (\PDOException $e) {
            throw new RepositoryException('Failed to store refresh token', layer: 'repository.oauth', previous: $e);
        }
    }

    public function findByHash(string $tokenHash): ?RefreshToken
    {
        try {
            $row = $this->db->queryOne(
                'SELECT * FROM oauth_refresh_tokens WHERE token_hash = :hash',
                ['hash' => $tokenHash],
            );
        } catch (\PDOException $e) {
            throw new RepositoryException('Failed to load refresh token', layer: 'repository.oauth', previous: $e);
        }

        if ($row === null) {
            return null;
        }

        $scopes = json_decode((string) ($row['scopes'] ?? '[]'), true);

        return RefreshToken::of(
            id:        (string) $row['id'],
            familyId:  (string) $row['family_id'],
            clientId:  (string) $row['client_id'],
            userId:    (string) $row['user_id'],
            scopes:    is_array($scopes) ? array_values(array_filter($scopes, 'is_string')) : [],
            expiresAt: new \DateTimeImmutable((string) $row['expires_at']),
            revoked:   (bool) $row['revoked'],
        );
    }

    /** @return list<RefreshToken> */
    public function findByUser(string $userId): array
    {
        try {
            $rows = $this->db->query(
                'SELECT * FROM oauth_refresh_tokens
                 WHERE user_id = :user AND revoked = 0 AND expires_at > :now
                 ORDER BY expires_at DESC',
                ['user' => $userId, 'now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s')],
            );
        } catch (\PDOException $e) {
            throw new RepositoryException('Failed to list refresh tokens', layer: 'repository.oauth', previous: $e);
        }

        return array_map($this->hydrate(...), $rows);
    }

    /** @return list<RefreshToken> every active grant in the tenant (admin view). */
    public function allActive(): array
    {
        try {
            $rows = $this->db->query(
                'SELECT * FROM oauth_refresh_tokens
                 WHERE revoked = 0 AND expires_at > :now
                 ORDER BY expires_at DESC',
                ['now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s')],
            );
        } catch (\PDOException $e) {
            throw new RepositoryException('Failed to list refresh tokens', layer: 'repository.oauth', previous: $e);
        }

        return array_map($this->hydrate(...), $rows);
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): RefreshToken
    {
        $scopes = json_decode((string) ($row['scopes'] ?? '[]'), true);

        return RefreshToken::of(
            id:        (string) $row['id'],
            familyId:  (string) $row['family_id'],
            clientId:  (string) $row['client_id'],
            userId:    (string) $row['user_id'],
            scopes:    is_array($scopes) ? array_values(array_filter($scopes, 'is_string')) : [],
            expiresAt: new \DateTimeImmutable((string) $row['expires_at']),
            revoked:   (bool) $row['revoked'],
        );
    }

    public function revokeIfActive(string $tokenId): bool
    {
        try {
            return $this->db->execute(
                'UPDATE oauth_refresh_tokens SET revoked = 1 WHERE id = :id AND revoked = 0',
                ['id' => $tokenId],
            ) === 1;
        } catch (\PDOException $e) {
            throw new RepositoryException('Failed to revoke refresh token', layer: 'repository.oauth', previous: $e);
        }
    }

    public function revokeFamily(string $familyId): int
    {
        try {
            return $this->db->execute(
                'UPDATE oauth_refresh_tokens SET revoked = 1 WHERE family_id = :family AND revoked = 0',
                ['family' => $familyId],
            );
        } catch (\PDOException $e) {
            throw new RepositoryException('Failed to revoke refresh token family', layer: 'repository.oauth', previous: $e);
        }
    }

    public function deleteExpired(?\DateTimeImmutable $now = null): int
    {
        $cutoff = ($now ?? new \DateTimeImmutable())->format('Y-m-d H:i:s');

        try {
            return $this->db->execute(
                'DELETE FROM oauth_refresh_tokens WHERE expires_at <= :cutoff',
                ['cutoff' => $cutoff],
            );
        } catch (\PDOException $e) {
            throw new RepositoryException('Failed to prune refresh tokens', layer: 'repository.oauth', previous: $e);
        }
    }
}
