<?php

declare(strict_types=1);

namespace Plugins\Authorization\API\Contracts;

/**
 * Published authorization contract.
 *
 * Other modules depend ONLY on this interface (declare 'authorization.policy'
 * in their module.json "requires"). The Casbin engine stays internal to this
 * plugin.
 */
interface AuthorizationServiceContract
{
    /**
     * Decide whether $subject may perform $action on $object.
     *
     * @param string ...$extra optional trailing request values (e.g. domain/tenant)
     */
    public function allows(string $subject, string $object, string $action, string ...$extra): bool;

    /**
     * Inverse of allows().
     */
    public function denies(string $subject, string $object, string $action, string ...$extra): bool;

    /**
     * Grant a role to a user (optionally scoped to a domain/tenant).
     */
    public function assignRole(string $user, string $role, ?string $domain = null): bool;

    /**
     * Revoke a role from a user.
     */
    public function revokeRole(string $user, string $role, ?string $domain = null): bool;

    /**
     * Roles currently held by a user.
     *
     * @return list<string>
     */
    public function rolesOf(string $user, ?string $domain = null): array;

    /**
     * Effective permissions for a user — their own grants PLUS everything
     * inherited through the role hierarchy, flattened to "object:action"
     * strings (the platform Identity->permissions convention).
     *
     * @return list<string>
     */
    public function permissionsOf(string $user, ?string $domain = null): array;

    /**
     * Add a permission policy rule: subject can do action on object.
     */
    public function grant(string $subject, string $object, string $action, string ...$extra): bool;

    /**
     * Remove a permission policy rule.
     */
    public function revoke(string $subject, string $object, string $action, string ...$extra): bool;
}
