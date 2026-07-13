<?php

declare(strict_types=1);

namespace Plugins\OAuth2\Application\Ports;

use Plugins\OAuth2\Domain\Exceptions\OAuthException;

/**
 * AuthorizationFlow — headless authorization_code(+PKCE) issuance for
 * FIRST-PARTY, ALREADY-AUTHENTICATED flows (published cross-module port).
 *
 * The browser flow renders a consent page at /oauth/authorize; a first-party
 * mobile login has already verified the resource owner's credentials, so it may
 * skip consent and mint the code directly (the old __DEV__ mobile login/register
 * flow). Client, redirect_uri, scopes and PKCE are still fully validated —
 * only the consent UI is bypassed.
 *
 * Call ONLY with a user your module has just authenticated itself.
 */
interface AuthorizationFlow
{
    /**
     * Validate an authorization request and mint a single-use code for the
     * given (authenticated) user.
     *
     * @param array<string,string> $params client_id, redirect_uri, scope, state,
     *                                     code_challenge, code_challenge_method.
     *                                     response_type defaults to 'code'.
     * @return array{code:string,state:string,redirect_uri:string}
     * @throws OAuthException when the client/redirect/scope/PKCE is invalid
     */
    public function issueCodeFor(array $params, string $userId): array;
}
