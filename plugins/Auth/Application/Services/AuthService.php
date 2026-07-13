<?php

declare(strict_types=1);

namespace Plugins\Auth\Application\Services;

use AlfacodeTeam\PhpServicePlatform\Kernel\Database\TransactionManager;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ServiceException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\CachePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\HashingPort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\SessionPort;
use Plugins\Auth\API\Contracts\AuthServiceContract;
use Plugins\Auth\API\DTOs\TokenDTO;
use Plugins\Auth\API\Guard;
use Plugins\Auth\Infrastructure\Persistence\PersonalAccessTokenRepository;
use Plugins\Auth\Security\JwtAuthLayer;
use Firebase\JWT\JWT;

/**
 * Issues authentication credentials (JWT + personal access tokens).
 *
 * Token verification is handled by the SecurityLayer classes; this service only
 * mints credentials. Personal access tokens are stored hashed; the plaintext is
 * returned to the caller exactly once.
 */
final class AuthService implements AuthServiceContract
{
    /** Session attribute keys for a web/AJAX login (read back by SessionAuthStage). */
    public const SESSION_USER        = 'auth.user_id';
    public const SESSION_ROLES       = 'auth.roles';
    public const SESSION_PERMISSIONS = 'auth.permissions';
    public const SESSION_TENANT      = 'auth.tenant';

    /**
     * @param string      $jwtSecret     HMAC secret (HS*). Required for symmetric algos.
     * @param string|null $jwtPrivateKey PEM private key for asymmetric algos (RS / ES / PS). When
     *                                   set with an asymmetric $jwtAlgo it is used to sign,
     *                                   so verifiers only ever need the public key.
     * @param string|null $jwtKid        Optional key id stamped in the JWT header for rotation.
     */
    public function __construct(
        private readonly PersonalAccessTokenRepository $tokens,
        private readonly HashingPort $hasher,
        private readonly string $jwtSecret,
        private readonly string $jwtAlgo = 'HS256',
        private readonly ?string $jwtIssuer = null,
        private readonly ?string $jwtAudience = null,
        private readonly ?CachePort $cache = null,
        private readonly ?string $jwtPrivateKey = null,
        private readonly ?string $jwtKid = null,
        private readonly ?\Plugins\Auth\Application\Auth\RoleResolver $roles = null,
        private readonly ?TransactionManager $transaction = null, // central-connection tx
    ) {
    } 

    /** Asymmetric algorithms sign with a private key rather than a shared secret. */
    private function isAsymmetric(): bool
    {
        return $this->jwtAlgo[0] === 'R' || $this->jwtAlgo[0] === 'E' || $this->jwtAlgo[0] === 'P';
    }

    public function issueJwt(string $userId, array $claims = [], int $ttlSeconds = 3600): string
    {
        $asymmetric = $this->isAsymmetric();
        $signingKey = $asymmetric ? (string) $this->jwtPrivateKey : $this->jwtSecret;

        if ($signingKey === '') {
            throw new ServiceException('auth.jwt.unconfigured', layer: 'service.auth');
        }

        // Tenant context travels on the signed `tnt` claim. Mint it ONLY after
        // the user selects a tenant and membership is verified against the
        // central `user_tenants` table; an access token issued at login carries
        // no tenant (empty) so it routes to the central connection only.
        // RBAC enrichment: when the caller didn't supply roles/permissions and
        // the Authorization plugin is loaded, resolve them from the policy store
        // so the access token carries the user's effective grants.
        $tenant = (string) ($claims['tnt'] ?? $claims['tenant'] ?? '');
        if (!isset($claims['roles']) && !isset($claims['permissions']) && $this->roles !== null) {
            $resolved            = $this->roles->forUser($userId, $tenant);
            $claims['roles']       = $resolved['roles'];
            $claims['permissions'] = $resolved['permissions'];
        }

        $now = time();
        $payload = [
            'sub'         => $userId,
            'tnt'         => $tenant,
            'roles'       => array_values($claims['roles'] ?? []),
            'permissions' => array_values($claims['permissions'] ?? []),
            'iat'         => $now,
            'nbf'         => $now,
            'exp'         => $now + max(1, $ttlSeconds),
            // Unique token id — enables targeted revocation / replay tracking.
            'jti'         => bin2hex(random_bytes(16)),
        ];

        // Registered claims for issuer/audience binding (verified by JwtAuthLayer).
        if ($this->jwtIssuer !== null && $this->jwtIssuer !== '') {
            $payload['iss'] = $this->jwtIssuer;
        }
        if ($this->jwtAudience !== null && $this->jwtAudience !== '') {
            $payload['aud'] = $this->jwtAudience;
        }

        $kid = ($this->jwtKid !== null && $this->jwtKid !== '') ? $this->jwtKid : null;

        return JWT::encode($payload, $signingKey, $this->jwtAlgo, $kid);
    }

    public function guard(Request $request): Guard
    {
        return Guard::fromRequest($request);
    }

    public function tokensFor(string $userId): array
    {
        return array_map(
            static fn (array $row): TokenDTO => TokenDTO::fromRow($row),
            $this->tokens->findByUser($userId),
        );
    }

    public function createPersonalAccessToken(
        string $userId,
        string $name = 'default',
        array $abilities = [],
        ?int $ttlSeconds = null,
    ): array {
        $id        = bin2hex(random_bytes(16));
        $plaintext = $id . '.' . bin2hex(random_bytes(32));
        $hash      = hash('sha256', $plaintext);

        $expiresAt = $ttlSeconds !== null && $ttlSeconds > 0
            ? (new \DateTimeImmutable())->add(new \DateInterval('PT' . $ttlSeconds . 'S'))
            : null;

        $this->transactional(fn () => $this->tokens->store($id, $userId, $name, $hash, $abilities, $expiresAt));

        return ['id' => $id, 'token' => $plaintext];
    }

    public function revokePersonalAccessToken(string $id): void
    {
        $this->transactional(fn () => $this->tokens->delete($id));
    }

    public function startSession(
        SessionPort $session,
        string $userId,
        array $roles = [],
        array $permissions = [],
        string $tenantId = '',
    ): void {
        // RBAC enrichment: when the caller passes no explicit roles/permissions
        // and Authorization is loaded, resolve the user's effective grants so the
        // session Identity carries them (parity with issueJwt()).
        if ($roles === [] && $permissions === [] && $this->roles !== null) {
            $resolved    = $this->roles->forUser($userId, $tenantId);
            $roles       = $resolved['roles'];
            $permissions = $resolved['permissions'];
        }

        // Session-fixation defence: rotate the id whenever the privilege level
        // changes (anonymous → authenticated). Existing flash data is preserved.
        $session->regenerate();

        $session->put(self::SESSION_USER, $userId);
        $session->put(self::SESSION_ROLES, array_values($roles));
        $session->put(self::SESSION_PERMISSIONS, array_values($permissions));
        $session->put(self::SESSION_TENANT, $tenantId);
    }

    public function endSession(SessionPort $session): void
    {
        // Drop every attribute AND rotate the id so the old cookie is dead.
        $session->invalidate();
    }

    public function revokeJwt(string $jti, int $ttlSeconds = 3600): void
    {
        if ($jti === '' || $this->cache === null) {
            return;
        }

        // Keep the deny-list entry at least as long as the token's remaining
        // life so it cannot be replayed after the cache entry would lapse.
        $this->cache->set(JwtAuthLayer::revocationKey($jti), 1, max(1, $ttlSeconds));
    }

    public function hashPassword(string $plain): string
    {
        return $this->hasher->make($plain);
    }

    public function verifyPassword(string $plain, string $hash): bool
    {
        return $this->hasher->check($plain, $hash);
    }

    /**
     * Bracket a unit of work in a transaction on the central auth connection.
     * Nesting-aware (TransactionManager), and a straight pass-through when no
     * manager was injected (unit tests with in-memory stores).
     */
    private function transactional(callable $work): mixed
    {
        if ($this->transaction === null) {
            return $work();
        }

        $this->transaction->begin();
        try {
            $result = $work();
            $this->transaction->commit();

            return $result;
        } catch (\Throwable $e) {
            $this->transaction->rollback();
            throw $e;
        }
    }
}
