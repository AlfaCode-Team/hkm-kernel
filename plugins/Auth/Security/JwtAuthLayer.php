<?php

declare(strict_types=1);

namespace Plugins\Auth\Security;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\CachePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Contracts\SecurityLayerContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\SecurityVerdict;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/**
 * Stateless JWT authentication layer.
 *
 * Fills the kernel's intended "AuthModule layer" slot: the kernel ships no
 * token validator, so this plugin provides one. Wire it in a project bootstrap:
 *
 *   ->withSecurity([
 *       new FirewallLayer(...),
 *       new RateLimiterLayer(...),
 *       new JwtAuthLayer(secret: env('JWT_SECRET'), algo: 'HS256'),
 *   ])
 *
 * Behaviour:
 *   - No Authorization header        -> allow as guest (public routes still work)
 *   - Valid Bearer token             -> allow with a resolved Identity
 *   - Malformed / invalid / expired  -> deny(401)
 *
 * Never throws — always returns a SecurityVerdict (GDA security rule).
 */
final class JwtAuthLayer implements SecurityLayerContract
{
    /**
     * @param string      $secret   HMAC secret (HS) or PEM public key (RS / ES).
     * @param string      $algo     Signing algorithm to accept (single algo — never trust the header `alg`).
     * @param string|null $issuer   When set, the `iss` claim MUST equal this value.
     * @param string|null $audience When set, the `aud` claim MUST contain this value.
     * @param int         $leeway   Clock-skew tolerance in seconds for exp/iat/nbf.
     * @param CachePort|null $revocations When set, the `jti` claim is checked against
     *        a deny-list so a token can be revoked before its natural expiry.
     */
    public function __construct(
        private readonly string $secret,
        private readonly string $algo = 'HS256',
        private readonly ?string $issuer = null,
        private readonly ?string $audience = null,
        private readonly int $leeway = 0,
        private readonly ?CachePort $revocations = null,
    ) {
    }

    /** Deny-list cache key for a revoked token id. */
    public static function revocationKey(string $jti): string
    {
        return 'auth:jwt:revoked:' . $jti;
    }

    public function check(Request $request): SecurityVerdict
    {
        $header = $request->header('Authorization') ?? '';
        if ($header === '' || !str_starts_with($header, 'Bearer ')) {
            // Anonymous request — let downstream authorization decide.
            return SecurityVerdict::allow($request);
        }

        $token = trim(substr($header, 7));
        if ($token === '' || $this->secret === '') {
            return SecurityVerdict::deny(401, 'Invalid or missing authentication token.');
        }

        // Honour configured clock-skew tolerance for exp/iat/nbf checks (the JWT
        // library reads this static at decode time).
        if ($this->leeway > 0) {
            JWT::$leeway = $this->leeway;
        }

        try {
            // Pin to a SINGLE algorithm — never let the token's own `alg` header
            // pick the verifier (prevents alg-confusion / HS-vs-RS downgrade).
            $claims = (array) JWT::decode($token, new Key($this->secret, $this->algo));
        } catch (\Throwable) {
            return SecurityVerdict::deny(401, 'Authentication token is invalid or expired.');
        }

        // Issuer / audience binding — reject tokens minted for another service or
        // tenant boundary even if the signature is valid.
        if ($this->issuer !== null && ($claims['iss'] ?? null) !== $this->issuer) {
            return SecurityVerdict::deny(401, 'Authentication token issuer is not trusted.');
        }
        if ($this->audience !== null && !$this->audienceMatches($claims['aud'] ?? null)) {
            return SecurityVerdict::deny(401, 'Authentication token audience is not accepted.');
        }

        // Revocation deny-list — a logged-out / compromised token is rejected
        // even though its signature and expiry are still valid. Fail OPEN on a
        // cache outage (the token is otherwise cryptographically valid) rather
        // than locking every user out when the cache is unreachable.
        $jti = (string) ($claims['jti'] ?? '');
        if ($this->revocations !== null && $jti !== '') {
            try {
                if ($this->revocations->has(self::revocationKey($jti))) {
                    return SecurityVerdict::deny(401, 'Authentication token has been revoked.');
                }
            } catch (\Throwable) {
                // Cache unavailable — proceed on the valid signature.
            }
        }

        // Tenant context rides on the signed `tnt` claim (legacy `tenant`
        // accepted for BC). Empty = UNSCOPED: the request keeps the central
        // connection (login, tenant picker, public pages). A non-empty tenant is
        // routed to its isolated DB by plugins/Tenancy's TenantContextStage,
        // which re-checks membership so a revoked seat loses access before expiry.
        $tenant = (string) ($claims['tnt'] ?? $claims['tenant'] ?? '');

        $identity = new Identity(
            userId:      (string) ($claims['sub'] ?? ''),
            tenantId:    $tenant,
            roles:       array_values((array) ($claims['roles'] ?? [])),
            permissions: array_values((array) ($claims['permissions'] ?? [])),
            tokenType:   'jwt',
            // Display-identity claims minted by AuthService::issueJwt() (OIDC
            // names). `name` is first + last from the tenant user_profiles table,
            // present only on tenant-scoped tokens.
            username:    (string) ($claims['preferred_username'] ?? ''),
            email:       (string) ($claims['email'] ?? ''),
            fullName:    (string) ($claims['name'] ?? ''),
        );

        return SecurityVerdict::allow($request->withIdentity($identity));
    }

    /** `aud` may be a single string or a list; accept when our audience is present. */
    private function audienceMatches(mixed $aud): bool
    {
        if (is_string($aud)) {
            return hash_equals($this->audience ?? '', $aud);
        }
        if (is_array($aud)) {
            foreach ($aud as $candidate) {
                if (is_string($candidate) && hash_equals($this->audience ?? '', $candidate)) {
                    return true;
                }
            }
        }

        return false;
    }
}
