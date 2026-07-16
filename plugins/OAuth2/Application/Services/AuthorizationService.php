<?php

declare(strict_types=1);

namespace Plugins\OAuth2\Application\Services;

use Plugins\OAuth2\Application\Ports\AuthCodeStore;
use Plugins\OAuth2\Application\Ports\AuthorizationFlow;
use Plugins\OAuth2\Application\Ports\ClientStore;
use Plugins\OAuth2\Domain\Entities\AuthCode;
use Plugins\OAuth2\Domain\Exceptions\OAuthException;
use Plugins\OAuth2\Domain\ValueObjects\Pkce;

/**
 * AuthorizationService — the Authorization Code grant's /authorize half
 * (RFC 6749 §4.1 + PKCE RFC 7636).
 *
 * validate() turns raw query params into a vetted AuthorizationRequest (client
 * exists, redirect_uri is an EXACT registered match, response_type=code, scopes
 * allowed, PKCE present for public clients). issueCode() mints a single-use,
 * short-lived code bound to all of the above.
 *
 * Security rules enforced:
 *   - redirect_uri exact match (no wildcards) — and validated BEFORE any error
 *     is redirected, so an attacker cannot harvest errors at an arbitrary URI.
 *   - PKCE is MANDATORY for public clients; S256 strongly preferred.
 *   - code is random, hashed at rest, 60s TTL, single-use.
 */
final class AuthorizationService implements AuthorizationFlow
{
    public function __construct(
        private readonly ClientStore $clients,
        private readonly AuthCodeStore $codes,
        private readonly ScopeValidator $scopeValidator,
        private readonly int $codeTtl = 60,
    ) {
    }

    /**
     * @param array<string,string> $params query parameters from /authorize
     * @throws OAuthException
     */
    public function validate(array $params): AuthorizationRequest
    {
        $clientId = trim($params['client_id'] ?? '');
        if ($clientId === '') {
            throw OAuthException::invalidRequest('Missing client_id.');
        }

        $client = $this->clients->find($clientId);
        if ($client === null || $client->revoked) {
            throw OAuthException::invalidClient('Unknown or revoked client.');
        }

        // Resolve + EXACT-match the redirect URI before trusting any redirect.
        $redirectUri = trim($params['redirect_uri'] ?? '');
        if ($redirectUri === '') {
            $redirectUri = $client->defaultRedirect() ?? '';
            if ($redirectUri === '' || count($client->redirectUris) !== 1) {
                throw OAuthException::invalidRequest('A redirect_uri is required.');
            }
        }
        if (!$client->allowsRedirect($redirectUri)) {
            throw OAuthException::invalidRequest('redirect_uri does not match a registered URI.');
        }

        if (!$client->allowsGrant('authorization_code')) {
            throw OAuthException::unauthorizedClient('Client may not use the authorization_code grant.');
        }

        // From here errors are redirectable (we trust redirect_uri now).
        $responseType = trim($params['response_type'] ?? '');
        if ($responseType !== 'code') {
            throw OAuthException::unsupportedResponseType();
        }

        $scopes = $this->scopeValidator->validate($params['scope'] ?? '', $client);

        // PKCE.
        $challenge = trim($params['code_challenge'] ?? '');
        $method    = trim($params['code_challenge_method'] ?? Pkce::METHOD_PLAIN);
        if ($challenge === '') {
            if ($client->isPublic()) {
                throw OAuthException::invalidRequest('PKCE code_challenge is required for public clients.');
            }
            $challenge = null;
            $method    = null;
        } elseif (!Pkce::supportsMethod($method)) {
            throw OAuthException::invalidRequest('Unsupported code_challenge_method.');
        }

        return new AuthorizationRequest(
            client:              $client,
            redirectUri:         $redirectUri,
            scopes:              $scopes,
            state:               (string) ($params['state'] ?? ''),
            codeChallenge:       $challenge,
            codeChallengeMethod: $method,
            nonce:               ($params['nonce'] ?? null) ?: null,
        );
    }

    /**
     * Issue an authorization code for an APPROVED request and return the full
     * redirect URL (with code + state) the user-agent should be sent to.
     */
    public function issueCode(AuthorizationRequest $req, string $userId): string
    {
        $rawCode  = bin2hex(random_bytes(32));
        $codeId   = bin2hex(random_bytes(16));
        $expires  = (new \DateTimeImmutable())->add(new \DateInterval('PT' . max(30, $this->codeTtl) . 'S'));

        $code = AuthCode::of(
            id:                  $codeId,
            clientId:            $req->client->id,
            userId:              $userId,
            redirectUri:         $req->redirectUri,
            scopes:              $req->scopes,
            codeChallenge:       $req->codeChallenge,
            codeChallengeMethod: $req->codeChallengeMethod,
            expiresAt:           $expires,
            nonce:               $req->nonce,
        );

        $this->codes->store($code, hash('sha256', $rawCode));

        return $this->buildRedirect($req->redirectUri, [
            'code'  => $rawCode,
            'state' => $req->state,
        ]);
    }

    /**
     * Headless validate + issue for a first-party, already-authenticated user
     * (AuthorizationFlow port — the old __DEV__ mobile login/register flow).
     * Consent is skipped; every other check (client, redirect_uri exact match,
     * scopes, PKCE-for-public-clients) still runs.
     */
    public function issueCodeFor(array $params, string $userId): array
    {
        $params['response_type'] = $params['response_type'] ?? 'code';

        $request  = $this->validate($params);
        $redirect = $this->issueCode($request, $userId);

        parse_str((string) parse_url($redirect, PHP_URL_QUERY), $query);

        return [
            'code'         => (string) ($query['code'] ?? ''),
            'state'        => (string) ($query['state'] ?? $request->state),
            'redirect_uri' => $request->redirectUri,
        ];
    }

    /** Build a redirect URL, appending params to any existing query string. */
    public function buildRedirect(string $uri, array $params): string
    {
        $params = array_filter($params, static fn ($v) => $v !== '' && $v !== null);
        $sep    = str_contains($uri, '?') ? '&' : '?';

        return $params === [] ? $uri : $uri . $sep . http_build_query($params);
    }
}
