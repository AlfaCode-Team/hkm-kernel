<?php

declare(strict_types=1);

namespace Plugins\Authorization\Application\Services;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ServiceException;
use Plugins\Authorization\API\Contracts\AuthorizationServiceContract;
use Plugins\Authorization\Engine\Enforcer;

/**
 * GDA service wrapping the Casbin Enforcer.
 *
 * The Enforcer is the only collaborator and stays internal to this plugin
 * (bound via bindInternal in the Provider). This class is the published
 * surface other modules reach through AuthorizationServiceContract.
 */
final class AuthorizationService implements AuthorizationServiceContract
{
    public function __construct(
        private readonly Enforcer $enforcer,
    ) {
    }

    public function allows(string $subject, string $object, string $action, string ...$extra): bool
    {
        try {
            return $this->enforcer->enforce($subject, $object, $action, ...$extra);
        } catch (\Throwable $e) {
            throw new ServiceException(
                'authorization.enforce.failed',
                layer: 'service.authorization',
                context: ['subject' => $subject, 'object' => $object, 'action' => $action],
                previous: $e,
            );
        }
    }

    public function denies(string $subject, string $object, string $action, string ...$extra): bool
    {
        return !$this->allows($subject, $object, $action, ...$extra);
    }

    public function assignRole(string $user, string $role, ?string $domain = null): bool
    {
        return $domain === null
            ? $this->enforcer->addRoleForUser($user, $role)
            : $this->enforcer->addRoleForUserInDomain($user, $role, $domain);
    }

    public function revokeRole(string $user, string $role, ?string $domain = null): bool
    {
        return $domain === null
            ? $this->enforcer->deleteRoleForUser($user, $role)
            : $this->enforcer->deleteRoleForUserInDomain($user, $role, $domain);
    }

    /** @return list<string> */
    public function rolesOf(string $user, ?string $domain = null): array
    {
        return $domain === null
            ? $this->enforcer->getRolesForUser($user)
            : $this->enforcer->getRolesForUserInDomain($user, $domain);
    }

    /** @return list<string> effective (own + role-inherited) "object:action" grants */
    public function permissionsOf(string $user, ?string $domain = null): array
    {
        $rules = $domain === null
            ? $this->enforcer->getImplicitPermissionsForUser($user)
            : $this->enforcer->getImplicitPermissionsForUser($user, $domain);

        $permissions = [];
        foreach ($rules as $rule) {
            // Rule shape: [sub, obj, act] (+ optional extras) — flatten to obj:act.
            $object = (string) ($rule[1] ?? '');
            $action = (string) ($rule[2] ?? '');
            if ($object !== '' && $action !== '') {
                $permissions[$object . ':' . $action] = true;
            }
        }

        return array_keys($permissions);
    }

    public function grant(string $subject, string $object, string $action, string ...$extra): bool
    {
        return $this->enforcer->addPolicy($subject, $object, $action, ...$extra);
    }

    public function revoke(string $subject, string $object, string $action, string ...$extra): bool
    {
        return $this->enforcer->removePolicy($subject, $object, $action, ...$extra);
    }
}
