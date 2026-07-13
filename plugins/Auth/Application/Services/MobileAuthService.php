<?php

declare(strict_types=1);

namespace Plugins\Auth\Application\Services;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ServiceException;
use Plugins\Auth\API\Contracts\AuthServiceContract;
use Plugins\OAuth2\Application\Ports\AuthorizationFlow;
use Plugins\User\API\Contracts\UserServiceContract;
use Plugins\User\API\DTOs\RegisterUserDTO;
use Plugins\User\API\DTOs\UserDTO;

/**
 * MobileAuthService — the old __DEV__ /v1/auth/* mobile flow, GDA-native.
 *
 * Two login shapes, detected by the presence of `client_id`:
 *
 *   PKCE   → credentials verified here, then a single-use authorization code is
 *            minted headlessly via the OAuth2 plugin's AuthorizationFlow port
 *            (no browser redirect loop). The client exchanges it at
 *            POST /oauth/token with its code_verifier.
 *   legacy → an access JWT + revocable refresh token pair, issued directly.
 *
 * Registration mirrors the old register→code flow: create the account, auto-
 * verify (mobile UX — configurable via AUTH_MOBILE_AUTOVERIFY), then hand back
 * either a PKCE code or a token pair. Logout blocklists the access token's JTI
 * for its remaining lifetime (AuthService::revokeJwt → JwtAuthLayer deny-list).
 */
final class MobileAuthService
{
    public function __construct(
        private readonly UserServiceContract $users,
        private readonly AuthServiceContract $auth,
        private readonly ?AuthorizationFlow $oauthFlow = null,
        private readonly bool $autoVerify = true,
    ) {
    }

    // ── PKCE code issuance ──────────────────────────────────────────────────────

    /**
     * Mint an authorization code for an ALREADY-VERIFIED user.
     *
     * @param array<string,string> $oauthParams client_id, redirect_uri, scope,
     *                                          state, code_challenge, code_challenge_method
     * @return array{code:string,state:string}
     * @throws \Plugins\OAuth2\Domain\Exceptions\OAuthException invalid client/redirect/scope/PKCE
     * @throws ServiceException when the OAuth2 module is not loaded for this route
     */
    public function issueCode(string $userId, array $oauthParams): array
    {
        if ($this->oauthFlow === null) {
            throw new ServiceException(
                'auth.mobile.oauth_unavailable',
                layer:   'service.auth.mobile',
                context: ['hint' => 'The oauth.server module must be required by this route.'],
            );
        }

        $issued = $this->oauthFlow->issueCodeFor($oauthParams, $userId);

        return ['code' => $issued['code'], 'state' => $issued['state']];
    }

    // ── Registration (old register→code flow) ───────────────────────────────────

    /**
     * Create the account and return the fresh UserDTO. Auto-verifies the email
     * (old mobile activate-on-register behaviour) unless disabled — the
     * plaintext verification token never leaves the server either way.
     */
    public function register(RegisterUserDTO $dto): UserDTO
    {
        $verificationToken = $this->users->registerPublic($dto);

        if ($this->autoVerify) {
            // Old flow parity: mobile users are activated immediately so the
            // code exchange isn't gated behind an inbox round-trip. Non-fatal —
            // a failure just leaves the account pending verification.
            try {
                $this->users->verifyEmailByToken($verificationToken);
            } catch (\Throwable) {
            }
        }

        $user = $this->users->findByIdentifier($dto->email->value());
        if ($user === null) {
            throw new ServiceException('auth.mobile.register.lookup_failed', layer: 'service.auth.mobile');
        }

        return $user;
    }

    // ── Logout (JTI blocklist) ──────────────────────────────────────────────────

    /**
     * Blocklist the presented access token's JTI for its remaining lifetime.
     * The token was already cryptographically verified by the `auth` filter —
     * this only READS the payload to find jti/exp; a malformed token is a no-op.
     */
    public function revokeAccessToken(?string $bearer): void
    {
        if ($bearer === null || $bearer === '') {
            return;
        }

        $parts = explode('.', $bearer);
        if (\count($parts) !== 3) {
            return;
        }

        $padded  = strtr($parts[1], '-_', '+/') . str_repeat('=', (4 - \strlen($parts[1]) % 4) % 4);
        $decoded = base64_decode($padded, strict: true);
        $payload = $decoded !== false ? json_decode($decoded, true) : null;

        if (!\is_array($payload) || !\is_string($payload['jti'] ?? null)) {
            return;
        }

        $remaining = (int) ($payload['exp'] ?? 0) - time();
        if ($remaining > 0) {
            $this->auth->revokeJwt($payload['jti'], $remaining);
        }
    }
}
