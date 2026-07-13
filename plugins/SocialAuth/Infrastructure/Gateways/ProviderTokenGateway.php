<?php

declare(strict_types=1);

namespace Plugins\SocialAuth\Infrastructure\Gateways;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\GatewayException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\HttpClientPort;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;

/**
 * ProviderTokenGateway — server-side verification of NATIVE-SDK sign-in tokens
 * (the old __DEV__ google()/apple() endpoints, done properly).
 *
 * Mobile apps sign in with the platform SDK and send us the resulting token;
 * we verify it against the provider before trusting any profile field:
 *
 *   google  access_token → GET googleapis userinfo (Bearer)
 *           id_token     → GET oauth2 tokeninfo + audience check
 *   apple   identity_token (JWT) → signature against Apple's JWKS
 *           + iss/aud/exp checks
 *
 * Returns a normalized profile: { id, email, name, nickname, avatar }.
 * Every failure throws GatewayException — no unverified payload ever escapes.
 */
final class ProviderTokenGateway
{
    private const GOOGLE_USERINFO  = 'https://www.googleapis.com/oauth2/v3/userinfo';
    private const GOOGLE_TOKENINFO = 'https://oauth2.googleapis.com/tokeninfo';
    private const APPLE_JWKS       = 'https://appleid.apple.com/auth/keys';
    private const APPLE_ISSUER     = 'https://appleid.apple.com';

    public function __construct(
        private readonly HttpClientPort $http,
        private readonly string $googleClientId = '',
        private readonly string $appleClientId = '',
    ) {
    }

    /**
     * @param array<string,string> $credentials driver-specific token fields
     * @return array{id:string,email:?string,name:?string,nickname:?string,avatar:?string}
     */
    public function verify(string $driver, array $credentials): array
    {
        return match ($driver) {
            'google' => $this->verifyGoogle($credentials),
            'apple'  => $this->verifyApple($credentials),
            default  => throw new GatewayException(
                "Token sign-in is not supported for provider [{$driver}].",
                layer: 'gateway.social_auth',
            ),
        };
    }

    // ── Google ──────────────────────────────────────────────────────────────────

    /** @param array<string,string> $credentials */
    private function verifyGoogle(array $credentials): array
    {
        $idToken     = trim((string) ($credentials['id_token'] ?? ''));
        $accessToken = trim((string) ($credentials['access_token'] ?? ''));

        if ($idToken !== '') {
            return $this->googleFromTokeninfo($idToken);
        }
        if ($accessToken !== '') {
            return $this->googleFromUserinfo($accessToken);
        }

        throw new GatewayException('Google sign-in requires id_token or access_token.', layer: 'gateway.social_auth.google');
    }

    /** @return array{id:string,email:?string,name:?string,nickname:?string,avatar:?string} */
    private function googleFromTokeninfo(string $idToken): array
    {
        $claims = $this->getJson(self::GOOGLE_TOKENINFO, ['id_token' => $idToken], 'gateway.social_auth.google');

        // tokeninfo already verified the signature; we must still pin the
        // audience to OUR client id or any Google app's token would sign in.
        if ($this->googleClientId !== '' && (string) ($claims['aud'] ?? '') !== $this->googleClientId) {
            throw new GatewayException('Google id_token audience mismatch.', layer: 'gateway.social_auth.google');
        }

        if ((string) ($claims['sub'] ?? '') === '') {
            throw new GatewayException('Google id_token verification failed.', layer: 'gateway.social_auth.google');
        }

        return [
            'id'       => (string) $claims['sub'],
            'email'    => $this->verifiedEmail($claims['email'] ?? null, $claims['email_verified'] ?? null),
            'name'     => isset($claims['name']) ? (string) $claims['name'] : null,
            'nickname' => null,
            'avatar'   => isset($claims['picture']) ? (string) $claims['picture'] : null,
        ];
    }

    /** @return array{id:string,email:?string,name:?string,nickname:?string,avatar:?string} */
    private function googleFromUserinfo(string $accessToken): array
    {
        $response = $this->http->request('GET', self::GOOGLE_USERINFO, [
            'headers' => ['Authorization' => 'Bearer ' . $accessToken],
        ]);
        if ($response->failed()) {
            throw new GatewayException('Google access_token verification failed.', layer: 'gateway.social_auth.google');
        }

        $info = $response->json();
        if (!\is_array($info) || (string) ($info['sub'] ?? '') === '') {
            throw new GatewayException('Google userinfo response was malformed.', layer: 'gateway.social_auth.google');
        }

        return [
            'id'       => (string) $info['sub'],
            'email'    => $this->verifiedEmail($info['email'] ?? null, $info['email_verified'] ?? null),
            'name'     => isset($info['name']) ? (string) $info['name'] : null,
            'nickname' => null,
            'avatar'   => isset($info['picture']) ? (string) $info['picture'] : null,
        ];
    }

    // ── Apple ───────────────────────────────────────────────────────────────────

    /** @param array<string,string> $credentials */
    private function verifyApple(array $credentials): array
    {
        $identityToken = trim((string) ($credentials['identity_token'] ?? ''));
        if ($identityToken === '') {
            throw new GatewayException('Apple sign-in requires identity_token.', layer: 'gateway.social_auth.apple');
        }

        $jwks = $this->getJson(self::APPLE_JWKS, [], 'gateway.social_auth.apple');

        try {
            $claims = (array) JWT::decode($identityToken, JWK::parseKeySet($jwks));
        } catch (\Throwable $e) {
            throw new GatewayException(
                'Apple identity_token signature verification failed.',
                layer: 'gateway.social_auth.apple',
                previous: $e,
            );
        }

        if ((string) ($claims['iss'] ?? '') !== self::APPLE_ISSUER) {
            throw new GatewayException('Apple identity_token issuer mismatch.', layer: 'gateway.social_auth.apple');
        }
        if ($this->appleClientId !== '' && (string) ($claims['aud'] ?? '') !== $this->appleClientId) {
            throw new GatewayException('Apple identity_token audience mismatch.', layer: 'gateway.social_auth.apple');
        }
        if ((string) ($claims['sub'] ?? '') === '') {
            throw new GatewayException('Apple identity_token verification failed.', layer: 'gateway.social_auth.apple');
        }

        // Apple sends the user's name only on FIRST authorization, as a separate
        // client-side field — accept it as a hint (it is not security-relevant).
        $name = trim((string) ($credentials['name'] ?? ''));

        return [
            'id'       => (string) $claims['sub'],
            'email'    => $this->verifiedEmail($claims['email'] ?? null, $claims['email_verified'] ?? null),
            'name'     => $name !== '' ? $name : null,
            'nickname' => null,
            'avatar'   => null,
        ];
    }

    // ── Shared ──────────────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function getJson(string $url, array $query, string $layer): array
    {
        $response = $this->http->get($url, $query);
        if ($response->failed()) {
            throw new GatewayException('Provider verification endpoint failed.', layer: $layer);
        }

        $json = $response->json();
        if (!\is_array($json)) {
            throw new GatewayException('Provider verification response was malformed.', layer: $layer);
        }

        return $json;
    }

    /** Only trust an email the PROVIDER says is verified. */
    private function verifiedEmail(mixed $email, mixed $verified): ?string
    {
        if (!\is_string($email) || trim($email) === '') {
            return null;
        }

        // email_verified arrives as bool or the strings "true"/"false".
        $isVerified = $verified === true || $verified === 'true' || $verified === 1 || $verified === '1';

        return $isVerified ? mb_strtolower(trim($email)) : null;
    }
}
