<?php

declare(strict_types=1);

namespace Plugins\OAuth2\Infrastructure\Http\Controllers;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\HashingPort;
use Plugins\OAuth2\Application\Ports\ClientStore;
use Plugins\OAuth2\Application\Ports\RefreshTokenStore;
use Plugins\OAuth2\Application\Ports\ScopeStore;
use Plugins\OAuth2\Domain\Entities\Client;
use Plugins\OAuth2\Domain\ValueObjects\GrantType;
use Plugins\OAuth2\Infrastructure\Http\Concerns\ChecksOAuthAdmin;
use Plugins\User\API\Contracts\UserServiceContract;
use Project\Http\Controllers\ApiController;

/**
 * Tenant-wide OAuth2 administration (NOT owner-scoped). Where /oauth/clients only
 * ever shows the caller's OWN clients, these endpoints let an administrator see and
 * manage EVERY client, scope and authorized grant in the current tenant DB.
 *
 * Access is gated on top of the `auth` filter: the caller must hold the admin role
 * (OAUTH_ADMIN_ROLE, default "admin") OR be listed in OAUTH_ADMIN_USERS. Everything
 * resolves the per-request DatabasePort, so an admin on a tenant host administers
 * that tenant's OAuth data.
 *
 *   GET/POST         /oauth/admin/clients
 *   PUT/DELETE       /oauth/admin/clients/{id}
 *   POST             /oauth/admin/clients/{id}/rotate
 *   GET/POST         /oauth/admin/scopes
 *   DELETE           /oauth/admin/scopes/{id}
 *   GET              /oauth/admin/authorized-tokens
 *   DELETE           /oauth/admin/authorized-tokens/{id}
 */
final class AdminController extends ApiController
{
    use ChecksOAuthAdmin;

    public function __construct(
        private readonly ClientStore $clients,
        private readonly ScopeStore $scopes,
        private readonly RefreshTokenStore $refreshTokens,
        private readonly HashingPort $hasher,
        private readonly UserServiceContract $users,
    ) {
    }

    // ── clients ──────────────────────────────────────────────────────────────

    public function clients(): Response
    {
        if ($deny = $this->guard()) {
            return $deny;
        }

        $all    = $this->clients->all();
        $owners = $this->resolveOwners($all);

        $clients = array_map(
            static function (Client $c) use ($owners): array {
                $ownerId = $c->ownerId();

                return $c->toPublicArray() + [
                    'owner_id' => $ownerId,
                    'owner'    => $ownerId !== null && $ownerId !== '' ? ($owners[$ownerId] ?? ['id' => $ownerId]) : null,
                ];
            },
            $all,
        );

        return $this->ok(['clients' => $clients]);
    }

    public function createClient(): Response
    {
        if ($deny = $this->guard()) {
            return $deny;
        }

        $name = trim((string) $this->request?->input('name', ''));
        if ($name === '') {
            return $this->unprocessable(['name' => 'A client name is required.']);
        }

        $redirects = $this->list($this->request?->input('redirect_uris', []));
        $scopes    = $this->list($this->request?->input('scopes', []));
        $public    = $this->request?->boolean('public') ?? false;

        $grants = $this->list($this->request?->input('grant_types', []));
        if ($grants === []) {
            $grants = ['authorization_code', 'refresh_token'];
        }

        // Grant types must be ones this server supports (RFC 6749 + RFC 8628).
        $badGrants = array_values(array_filter($grants, static fn(string $g) => GrantType::tryFromString($g) === null));
        if ($badGrants !== []) {
            return $this->unprocessable(['grant_types' => 'Unsupported grant type(s): ' . implode(', ', $badGrants) . '.']);
        }
        if (in_array('authorization_code', $grants, true) && $redirects === []) {
            return $this->unprocessable(['redirect_uris' => 'authorization_code requires at least one redirect URI.']);
        }

        // Every requested scope must exist in the catalogue (add them in Scopes first).
        $missing = $this->missingScopes($scopes);
        if ($missing !== []) {
            return $this->unprocessable(['scopes' => 'Unknown scope(s): ' . implode(', ', $missing) . '. Register them under Scopes first.']);
        }

        $id         = bin2hex(random_bytes(16));
        $secret     = null;
        $secretHash = null;
        if (!$public) {
            $secret     = bin2hex(random_bytes(32));
            $secretHash = $this->hasher->make($secret);
        }

        $this->clients->create($id, $name, $secretHash, $redirects, $grants, $scopes, !$public, $this->identity()->userId);

        return $this->created(array_filter([
            'id'            => $id,
            'name'          => $name,
            'client_secret' => $secret,
            'redirect_uris' => $redirects,
            'grant_types'   => $grants,
            'scopes'        => $scopes,
            'confidential'  => !$public,
        ], static fn($v) => $v !== null));
    }

    public function updateClient(string $id): Response
    {
        if ($deny = $this->guard()) {
            return $deny;
        }

        $client = $this->clients->find($id);
        if ($client === null) {
            return Response::notFound();
        }

        $name = trim((string) $this->request?->input('name', $client->name));
        if ($name === '') {
            return $this->unprocessable(['name' => 'A client name is required.']);
        }

        $redirects = $this->list($this->request?->input('redirect_uris', $client->redirectUris ?? []));
        $scopes    = $this->list($this->request?->input('scopes', $client->scopes ?? []));

        $missing = $this->missingScopes($scopes);
        if ($missing !== []) {
            return $this->unprocessable(['scopes' => 'Unknown scope(s): ' . implode(', ', $missing) . '.']);
        }

        $this->clients->updateDetails($id, $name, $redirects, $scopes);

        return $this->ok($this->clients->find($id)?->toPublicArray() ?? []);
    }

    public function rotateClient(string $id): Response
    {
        if ($deny = $this->guard()) {
            return $deny;
        }

        $secret = bin2hex(random_bytes(32));
        if (!$this->clients->updateSecret($id, $this->hasher->make($secret))) {
            return $this->unprocessable(['id' => 'No confidential client found for this id.']);
        }

        return $this->ok(['id' => $id, 'client_secret' => $secret]);
    }

    public function revokeClient(string $id): Response
    {
        if ($deny = $this->guard()) {
            return $deny;
        }

        return $this->clients->revoke($id) ? $this->noContent() : Response::notFound();
    }

    // ── scopes ───────────────────────────────────────────────────────────────

    public function scopes(): Response
    {
        if ($deny = $this->guard()) {
            return $deny;
        }

        $scopes = [];
        foreach ($this->scopes->describe() as $id => $description) {
            $scopes[] = ['id' => $id, 'description' => $description];
        }

        return $this->ok(['scopes' => $scopes]);
    }

    public function createScope(): Response
    {
        if ($deny = $this->guard()) {
            return $deny;
        }

        $id = trim((string) $this->request?->input('id', ''));
        if ($id === '' || preg_match('/^[A-Za-z0-9_:.\-]+$/', $id) !== 1) {
            return $this->unprocessable(['id' => 'A valid scope id is required (letters, digits, _ : . -).']);
        }

        $this->scopes->put($id, trim((string) $this->request?->input('description', '')));

        return $this->created(['id' => $id]);
    }

    public function deleteScope(string $id): Response
    {
        if ($deny = $this->guard()) {
            return $deny;
        }

        return $this->scopes->delete($id) ? $this->noContent() : Response::notFound();
    }

    // ── authorized tokens (all users) ────────────────────────────────────────

    public function authorizedTokens(): Response
    {
        if ($deny = $this->guard()) {
            return $deny;
        }

        $tokens = array_map(static fn($t): array => [
            'id'         => (string) $t->id,
            'client_id'  => (string) $t->clientId,
            'user_id'    => (string) $t->userId,
            'scopes'     => $t->scopes ?? [],
            'expires_at' => $t->expiresAt->format(\DateTimeInterface::RFC3339),
        ], $this->refreshTokens->allActive());

        return $this->ok(['authorized_tokens' => $tokens]);
    }

    public function revokeToken(string $id): Response
    {
        if ($deny = $this->guard()) {
            return $deny;
        }

        foreach ($this->refreshTokens->allActive() as $token) {
            if ((string) $token->id === $id) {
                $this->refreshTokens->revokeFamily((string) $token->familyId);

                return $this->noContent();
            }
        }

        return Response::notFound();
    }

    // ── access gate ──────────────────────────────────────────────────────────

    /** Returns a deny Response when the caller is not an authenticated admin. */
    private function guard(): ?Response
    {
        $identity = $this->identity();
        if ($identity->isGuest()) {
            return Response::unauthorized('Authentication required.');
        }

        return $this->isOAuthAdmin($identity) ? null : Response::forbidden('Administrator access required.');
    }

    /** @return list<string> */
    private function list(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }

    /**
     * Scopes from the given list that are NOT registered in the catalogue.
     *
     * @param list<string> $scopes
     * @return list<string>
     */
    private function missingScopes(array $scopes): array
    {
        return array_values(array_filter($scopes, fn(string $s): bool => !$this->scopes->exists($s)));
    }

    /**
     * Resolve each client owner's display profile (avatar / full name / email)
     * from the central user store — deduped and best-effort (a deleted or
     * unresolvable owner falls back to just its id).
     *
     * @param list<Client> $clients
     * @return array<string,array{id:string,username?:string,email?:string,full_name?:string,avatar_url?:string|null}>
     */
    private function resolveOwners(array $clients): array
    {
        $ids = [];
        foreach ($clients as $c) {
            $id = $c->ownerId();
            if ($id !== null && $id !== '') {
                $ids[$id] = true;
            }
        }

        $map = [];
        foreach (array_keys($ids) as $id) {
            try {
                $user = $this->users->find($id);
            } catch (\Throwable) {
                $user = null;
            }

            $map[$id] = $user === null
                ? ['id' => $id]
                : [
                    'id'         => $user->id,
                    'username'   => $user->username,
                    'email'      => $user->email,
                    'full_name'  => $user->fullName,
                    'avatar_url' => $user->avatarUrl,
                ];
        }

        return $map;
    }
}
