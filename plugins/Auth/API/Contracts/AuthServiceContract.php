<?php

declare(strict_types=1);

namespace Plugins\Auth\API\Contracts;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\SessionPort;
use Plugins\Auth\API\DTOs\TokenDTO;
use Plugins\Auth\API\Guard;

/**
 * Published authentication contract.
 *
 * Issues credentials; verification happens in the SecurityLayer classes that
 * run inside the SecurityGateway before any module loads.
 */
interface AuthServiceContract
{
    /**
     * Project the request's resolved Identity into a read-only Guard — the
     * GDA-native replacement for the old AuthManager's named guards. The
     * SecurityGateway chain already decided who authenticated and how; this just
     * exposes it ergonomically ($guard->check()/id()/via()/hasScope()).
     */
    public function guard(Request $request): Guard;

    /**
     * List the personal access tokens issued to a user (newest first), without
     * any secret material. Replaces the old HasApiTokens `tokens()` accessor.
     *
     * @return list<TokenDTO>
     */
    public function tokensFor(string $userId): array;

    /**
     * Issue a signed JWT for a user.
     *
     * Tenant context is carried by the `tnt` claim (multi-tenant control plane).
     * Omit it (or pass '') for a login/unscoped token that routes to the central
     * connection; set it ONLY after verifying membership in the central
     * `user_tenants` table at tenant-selection time. `tenant` is accepted as a
     * legacy alias.
     *
     * Display-identity claims (OIDC names) may be supplied: `preferred_username`,
     * `email`, `name` (full name from the tenant user_profiles table). When
     * username/email are omitted they are filled from the central user record;
     * `name` is only minted by tenant-aware callers (tenant selection).
     *
     * @param array{roles?:list<string>,permissions?:list<string>,tnt?:string,tenant?:string,preferred_username?:string,email?:string,name?:string} $claims
     */
    public function issueJwt(string $userId, array $claims = [], int $ttlSeconds = 3600): string;

    /**
     * Create a personal access token. Returns the plaintext token ONCE; only a
     * hash is persisted.
     *
     * @param list<string> $abilities  Scopes granted to the token (become Identity.permissions).
     * @param int|null     $ttlSeconds Absolute lifetime; null = non-expiring token.
     *
     * @return array{id:string,token:string}
     */
    public function createPersonalAccessToken(
        string $userId,
        string $name = 'default',
        array $abilities = [],
        ?int $ttlSeconds = null,
    ): array;

    /**
     * Revoke a personal access token by its id.
     */
    public function revokePersonalAccessToken(string $id): void;

    /**
     * Establish a stateful web/AJAX login on the given session.
     *
     * Rotates the session id (fixation defence) and stores the identity so
     * SessionAuthStage can rebuild an Identity on subsequent requests. Call AFTER
     * verifying credentials (e.g. UserServiceContract::verifyCredentials).
     *
     * Display identity: username/email are filled from the central user record
     * when omitted; $fullName (first + last from the tenant user_profiles table)
     * is stored as supplied — pass it when tenant context is known.
     *
     * @param list<string> $roles
     * @param list<string> $permissions
     */
    public function startSession(
        SessionPort $session,
        string $userId,
        array $roles = [],
        array $permissions = [],
        string $tenantId = '',
        string $username = '',
        string $email = '',
        string $fullName = '',
        ?string $avatarUrl = null,
    ): void;

    /** Tear down a web/AJAX login: clear attributes and rotate the session id. */
    public function endSession(SessionPort $session): void;

    /**
     * Revoke a JWT before its natural expiry by deny-listing its `jti` claim.
     * No-op when no cache/revocation backend is configured.
     *
     * @param string $jti        The token's unique id (the `jti` claim).
     * @param int    $ttlSeconds Keep the deny-list entry at least this long
     *                           (set it to the token's remaining lifetime).
     */
    public function revokeJwt(string $jti, int $ttlSeconds = 3600): void;

    /**
     * Hash a plaintext password for storage (bcrypt/argon2 via HashingPort).
     */
    public function hashPassword(string $plain): string;

    /**
     * Verify a plaintext password against a stored hash (timing-safe).
     */
    public function verifyPassword(string $plain, string $hash): bool;
}
