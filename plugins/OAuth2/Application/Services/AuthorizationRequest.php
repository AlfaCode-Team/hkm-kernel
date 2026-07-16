<?php

declare(strict_types=1);

namespace Plugins\OAuth2\Application\Services;

use Plugins\OAuth2\Domain\Entities\Client;

/**
 * AuthorizationRequest — a validated /authorize request ready for user consent.
 * Immutable; produced by AuthorizationService::validate().
 */
final class AuthorizationRequest
{
    /** @param list<string> $scopes */
    public function __construct(
        public readonly Client $client,
        public readonly string $redirectUri,
        public readonly array $scopes,
        public readonly string $state,
        public readonly ?string $codeChallenge,
        public readonly ?string $codeChallengeMethod,
        public readonly ?string $nonce,
    ) {
    }

    /** Serialise to a hidden-field map for the consent form round-trip. */
    public function toFormState(): array
    {
        return [
            // validate() re-checks the full parameter set on the consent POST,
            // including response_type — omitting it made every approval fail
            // with unsupported_response_type.
            'response_type'         => 'code',
            'client_id'             => $this->client->id,
            'redirect_uri'          => $this->redirectUri,
            'scope'                 => implode(' ', $this->scopes),
            'state'                 => $this->state,
            'code_challenge'        => (string) $this->codeChallenge,
            'code_challenge_method' => (string) $this->codeChallengeMethod,
            'nonce'                 => (string) $this->nonce,
        ];
    }
}
