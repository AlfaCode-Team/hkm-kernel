<?php

declare(strict_types=1);

namespace Plugins\Auth\Application\Auth;

use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use Plugins\Auth\API\Contracts\AuthServiceContract;
use Plugins\Auth\API\DTOs\TokenDTO;
use Plugins\Auth\Application\Ports\Authenticatable;
use Plugins\User\API\DTOs\UserDTO;

/**
 * AuthUserProxy — the lightweight authenticated "current user".
 *
 * GDA-native successor to the old __DEV__ AuthUserProxy: it carries only the
 * identity-relevant fields (no ORM hydration, no password hash — the store hides
 * that) and, crucially, EMITS a kernel Identity via identity(). The kernel
 * Identity stays the security principal; this proxy is the ergonomic wrapper the
 * AuthManager hands back from guard()->user().
 *
 * Roles/permissions/tenant/credential-type are supplied by whichever guard
 * resolved the user (the gateway verdict for token guards; the session store for
 * the session guard), since the central User record does not itself model RBAC.
 */
final readonly class AuthUserProxy implements Authenticatable
{
    /**
     * @param list<string> $roles
     * @param list<string> $permissions
     */
    private function __construct(
        private string $userId,
        private string $username,
        private string $email,
        private array $roles,
        private array $permissions, 
        private string $tenantId,
        private string $tokenType,
        private string $joinedAt,
        private ?AuthServiceContract $tokensService = null,
        private ?TokenDTO $accessToken = null,
    ) {}

    /**
     * Build from a UserDTO plus the security context resolved by the guard. Pass
     * $tokensService to enable the HasApiTokens surface (tokens/createToken).
     *
     * @param list<string> $roles
     * @param list<string> $permissions
     */
    public static function fromUser(
        UserDTO $user,
        array $permissions = [],
        string $tokenType = 'session',
        ?AuthServiceContract $tokensService = null,
    ): self {
        return new self(
            userId:        $user->id,
            username:      $user->username,
            email:         $user->email,
            roles:         array_values($user->roles),
            permissions:   array_values($permissions),
            tenantId:      $user->tenantId ?? "",
            tokenType:     $tokenType,
            tokensService: $tokensService,
            joinedAt:      $user->joinedAt ?? "",
        );
    }

    /**
     * Overlay the security context resolved by a guard (roles/permissions from
     * the session or the gateway verdict), returning an immutable copy.
     *
     * @param list<string> $roles
     * @param list<string> $permissions
     */
    public function withSecurity(array $roles, array $permissions, string $tenantId, string $tokenType): self
    {
        return new self(
            $this->userId,
            $this->username,
            $this->email,
            array_values($roles),
            array_values($permissions),
            $tenantId,
            $tokenType,
            $this->joinedAt,
            $this->tokensService,
            $this->accessToken,
        );
    }

    /** Attach the access token this request authenticated with (immutable copy). */
    public function withAccessToken(TokenDTO $token): self
    {
        return new self(
            $this->userId,
            $this->username,
            $this->email,
            $this->roles,
            $this->permissions,
            $this->tenantId,
            $this->tokenType,
            $this->joinedAt,
            $this->tokensService,
            $token,
        );
    }

    // ── HasApiTokens (needs a tokens service; else degrades gracefully) ──────────

    /**
     * All personal access tokens issued to this user (no secret material).
     *
     * @return list<TokenDTO>
     */
    public function tokens(): array
    {
        return $this->tokensService?->tokensFor($this->userId) ?? [];
    }

    /** The access token this request authenticated with, if any. */
    public function token(): ?TokenDTO
    {
        return $this->accessToken;
    }

    /**
     * Whether the current access token carries the given ability, OR — when no
     * explicit token is attached — the user's permissions grant it (covers
     * session/JWT callers). Mirrors the old tokenCan().
     */
    public function tokenCan(string $ability): bool
    {
        if ($this->accessToken !== null) {
            return $this->accessToken->can($ability);
        }

        return \Plugins\Auth\API\ScopeInheritance::satisfies($this->permissions, $ability);
    }

    /**
     * Mint a new personal access token for this user.
     *
     * @param list<string> $abilities
     * @return array{id:string,token:string}
     */
    public function createToken(string $name, array $abilities = [], ?int $ttlSeconds = null): array
    {
        if ($this->tokensService === null) {
            throw new \LogicException('AuthUserProxy has no tokens service — resolve it with a service to mint tokens.');
        }

        return $this->tokensService->createPersonalAccessToken($this->userId, $name, $abilities, $ttlSeconds);
    }

    public function getAuthIdentifier(): string
    {
        return $this->userId;
    }

    public function getAuthIdentifierName(): string
    {
        return 'user_id';
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function identity(): Identity
    {
        return new Identity(
            userId:      $this->userId,
            tenantId:    $this->tenantId,
            roles:       $this->roles,
            permissions: $this->permissions,
            tokenType:   $this->tokenType,
        );
    }
}
