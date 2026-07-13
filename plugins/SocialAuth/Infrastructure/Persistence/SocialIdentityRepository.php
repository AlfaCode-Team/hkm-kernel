<?php

declare(strict_types=1);

namespace Plugins\SocialAuth\Infrastructure\Persistence;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\RepositoryException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;

/**
 * Persists provider-account → user links via DatabasePort only.
 *
 * Table: social_identities(provider, provider_user_id, user_id, email, name,
 *        avatar, created_at, updated_at)
 */
final class SocialIdentityRepository
{
    public function __construct(
        private readonly DatabasePort $db,
        private readonly string $table = 'social_identities',
    ) {
    }

    /** The linked platform user id for a provider account, or null. */
    public function findUserId(string $provider, string $providerUserId): ?string
    {
        try {
            $row = $this->db->queryOne(
                "SELECT user_id FROM {$this->table} WHERE provider = :provider AND provider_user_id = :pid",
                ['provider' => $provider, 'pid' => $providerUserId]
            );
        } catch (\PDOException $e) {
            throw new RepositoryException('Failed to look up social identity', layer: 'repository.social_auth', previous: $e);
        }

        return $row !== null ? (string) $row['user_id'] : null;
    }

    /** Link (or refresh the snapshot of) a provider account for a user. */
    public function link(
        string $provider,
        string $providerUserId,
        string $userId,
        ?string $email,
        ?string $name,
        ?string $avatar,
    ): void {
        try {
            $this->db->upsert(
                $this->table,
                [
                    'provider'         => $provider,
                    'provider_user_id' => $providerUserId,
                    'user_id'          => $userId,
                    'email'            => $email !== null ? mb_substr($email, 0, 150) : null,
                    'name'             => $name !== null ? mb_substr($name, 0, 120) : null,
                    'avatar'           => $avatar !== null ? mb_substr($avatar, 0, 255) : null,
                    'updated_at'       => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                ],
                ['provider', 'provider_user_id'],
                // Refresh the snapshot columns; never move the link to another
                // user implicitly (user_id excluded from the update set).
                ['email', 'name', 'avatar', 'updated_at'],
            );
        } catch (\PDOException $e) {
            throw new RepositoryException('Failed to link social identity', layer: 'repository.social_auth', previous: $e);
        }
    }

    /**
     * All linked providers for a user (for a "connected accounts" screen).
     *
     * @return list<array{provider:string,provider_user_id:string,email:?string,name:?string,avatar:?string,created_at:?string}>
     */
    public function listForUser(string $userId): array
    {
        try {
            $rows = $this->db->query(
                "SELECT provider, provider_user_id, email, name, avatar, created_at
                 FROM {$this->table} WHERE user_id = :user_id ORDER BY provider",
                ['user_id' => $userId]
            );
        } catch (\PDOException $e) {
            throw new RepositoryException('Failed to list social identities', layer: 'repository.social_auth', previous: $e);
        }

        return array_values($rows);
    }

    /** Unlink one provider account from a user. True when a row was removed. */
    public function unlink(string $userId, string $provider): bool
    {
        try {
            return $this->db->execute(
                "DELETE FROM {$this->table} WHERE user_id = :user_id AND provider = :provider",
                ['user_id' => $userId, 'provider' => $provider]
            ) > 0;
        } catch (\PDOException $e) {
            throw new RepositoryException('Failed to unlink social identity', layer: 'repository.social_auth', previous: $e);
        }
    }
}
