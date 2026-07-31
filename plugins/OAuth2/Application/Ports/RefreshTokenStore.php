<?php

declare(strict_types=1);

namespace Plugins\OAuth2\Application\Ports;

use Plugins\OAuth2\Domain\Entities\RefreshToken;

interface RefreshTokenStore
{
    public function store(RefreshToken $token, string $tokenHash): void;

    public function findByHash(string $tokenHash): ?RefreshToken;

    /**
     * Active (non-revoked, non-expired) refresh tokens for a user — the apps the
     * user has authorized. Powers the self-service authorized-tokens endpoint.
     *
     * @return list<RefreshToken>
     */
    public function findByUser(string $userId): array;

    /**
     * Every active (non-revoked, non-expired) refresh token in the tenant —
     * ADMIN view of all authorized grants across users.
     *
     * @return list<RefreshToken>
     */
    public function allActive(): array;

    /** Atomically revoke if currently active; false when it was already revoked. */
    public function revokeIfActive(string $tokenId): bool;

    /** Revoke every token in a rotation family (reuse-detection response). */
    public function revokeFamily(string $familyId): int;

    public function deleteExpired(?\DateTimeImmutable $now = null): int;
}
