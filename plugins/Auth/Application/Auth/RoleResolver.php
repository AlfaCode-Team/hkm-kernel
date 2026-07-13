<?php

declare(strict_types=1);

namespace Plugins\Auth\Application\Auth;

use Plugins\Authorization\API\Contracts\AuthorizationServiceContract;

/**
 * RoleResolver — bridges login/issuance to the Casbin policy engine.
 *
 * When the Authorization plugin is loaded for the request, a freshly
 * authenticated user's roles + effective permissions are read from the policy
 * store and stamped into the session and JWT claims, so downstream permission
 * checks (Identity->hasRole/hasPermission, the `can` filter, service gates) see
 * the same picture. When Authorization is absent it degrades to empty lists —
 * auth still works, there is simply no RBAC data to carry.
 */
final class RoleResolver
{
    public function __construct(
        private readonly ?AuthorizationServiceContract $authz = null,
    ) {
    }

    /**
     * @return array{roles: list<string>, permissions: list<string>}
     */
    public function forUser(string $userId, string $tenantId = ''): array
    {
        if ($this->authz === null || $userId === '') {
            return ['roles' => [], 'permissions' => []];
        }

        $domain = $tenantId !== '' ? $tenantId : null;

        return [
            'roles'       => $this->authz->rolesOf($userId, $domain),
            'permissions' => $this->authz->permissionsOf($userId, $domain),
        ];
    }
}
