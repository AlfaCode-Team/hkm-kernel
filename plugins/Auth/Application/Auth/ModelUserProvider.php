<?php

declare(strict_types=1);

namespace Plugins\Auth\Application\Auth;

use Plugins\Auth\API\Contracts\AuthServiceContract;
use Plugins\Auth\Application\Ports\Authenticatable;
use Plugins\Auth\Application\Ports\UserProvider;
use Plugins\User\API\Contracts\UserServiceContract;

/**
 * ModelUserProvider — the default UserProvider service, backed by the central
 * identity store (UserServiceContract), NOT an ORM model.
 *
 * GDA-native successor to the old __DEV__ ModelUserProvider: it delegates every
 * lookup to the published User contract (which is timing-safe, rate-limited and
 * hides the password hash) and returns a lightweight AuthUserProxy. No entity
 * hydration, no `app()`, no global project entity.
 *
 * Tenant-membership gate: membership is part of the FETCH — id lookups pass
 * checkMembership=true to the User contract, so on a tenant-scoped request a
 * user without an active seat in that tenant simply does not exist for
 * authentication purposes (retrieve* returns null, indistinguishable from a
 * missing user). Credential/remember-token lookups enforce the same rule
 * inside the User service.
 */
final class ModelUserProvider implements UserProvider
{
    /**
     * @param list<string> $lookupFields ordered credential keys to try
     */
    public function __construct(
        private readonly UserServiceContract $users,
        private readonly string $providerName = 'users',
        private readonly array $lookupFields = ['identifier', 'email', 'username'],
        private readonly ?AuthServiceContract $tokens = null,
    ) {}

    public function name(): string
    {
        return $this->providerName;
    }

    public function retrieveById(string $id): ?Authenticatable
    {
        if ($id === '') {
            return null;
        }

        return $this->proxy($this->users->find($id, true,true));
    }

    public function retrieveByToken(string $rememberToken): ?Authenticatable
    {
        return $this->proxy($this->users->findByRememberToken($rememberToken));
    }

    public function retrieveByCredentials(array $credentials): ?Authenticatable
    {
        $identifier = '';
        foreach ($this->lookupFields as $field) {
            if (($credentials[$field] ?? '') !== '') {
                $identifier = (string) $credentials[$field];
                break;
            }
        }

        $password = (string) ($credentials['password'] ?? '');
        if ($identifier === '' || $password === '') {
            return null;
        }

        $user = $this->users->verifyCredentials($identifier, $password);
      

        // Single timing-safe verify (unknown user, wrong password, inactive, or
        // lockout all return null). The store never exposes the hash.
        return $this->proxy($user);
    }

    /**
     * Wrap a fetched user in the auth proxy. Membership was already enforced by
     * the User contract during the fetch, so a user without an active seat in
     * the request's tenant arrives here as null — indistinguishable from a
     * non-existent user.
     */
    private function proxy(?\Plugins\User\API\DTOs\UserDTO $user): ?Authenticatable
    {
        if ($user === null) {
            return null;
        }

        return AuthUserProxy::fromUser(
            $user,
            tokensService: $this->tokens,
        );
    }
}
