<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Security;

final readonly class Identity
{
    public function __construct(
        public readonly string $userId,
        public readonly string $tenantId,
        public readonly array $roles,
        public readonly array $permissions,
        public readonly string $tokenType,
        public readonly string $username = '',
        public readonly string $email = '',
        // First + last name from the TENANT user_profiles table — only present
        // when the credential was minted with tenant context (empty otherwise).
        public readonly string $fullName = '',
        // Avatar URL from the TENANT user_profiles table — only present when the
        // credential was minted with tenant context (empty otherwise).
        public readonly ?string $avatarUrl = null,
    ) {}

    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roles, true);
    }

    public function hasPermission(string $permission): bool
    {
        return in_array('*', $this->permissions, true)
            || in_array($permission, $this->permissions, true);
    }

    public function isGuest(): bool
    {
        return empty($this->userId);
    }

    /** @param list<string> $roles @param list<string> $permissions */
    public static function asUser(
        string $userId,
        string $tenantId = 'tenant-1',
        array $roles = ['user'],
        array $permissions = [],
        string $tokenType = 'jwt',
        string $username = '',
        string $email = '',
        string $fullName = '',
        ?string $avatarUrl = null,
    ): self {
        return new self($userId, $tenantId, $roles, $permissions, $tokenType, $username, $email, $fullName, $avatarUrl);
    }

    /** @param list<string> $permissions */
    public static function asAdmin(
        string $tenantId = 'tenant-1',
        string $userId = 'admin-user',
        array $permissions = ['*'],
    ): self {
        return new self($userId, $tenantId, ['admin'], $permissions, 'jwt');
    }

    public static function guest(): self
    {
        return new self('', '', [], [], 'none');
    }
}
