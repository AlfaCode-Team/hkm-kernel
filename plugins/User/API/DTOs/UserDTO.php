<?php

declare(strict_types=1);

namespace Plugins\User\API\DTOs;

use Plugins\User\Domain\Entities\User;

/**
 * Outward-facing representation of a user. Deliberately omits the password hash
 * and remember token — those credentials NEVER cross the API boundary.
 */
final readonly class UserDTO
{
    public function __construct(
        public string $id,
        public string $username,
        public string $email,
        public bool $emailVerified,
        public string $createdAt,
        public array $roles = [],
        public array $permissions = [],
        public ?string $tenantId = null,
        public ?string $joinedAt = null,
        // "First Last" from the TENANT user_profiles table. The central `users`
        // row carries no name, so this is '' until a tenant-aware flow attaches
        // it via withFullName() (e.g. UserService::find with a membership).
        public string $fullName = '',

        public ?string $avatarUrl = null,
    ) {
    }



    public static function fromEntity(User $user): self
    {
        $roles = $user->getMembership()?->role;
        $fullName = $user->getProfile()?->fullName();
        return new self(
            id: $user->id(),
            username: $user->username(),
            email: $user->email(),
            fullName: $fullName ?? '',
            avatarUrl: $user->getProfile()?->avatarUrl(),
            emailVerified: $user->isEmailVerified(),
            roles: $roles !== null ? [$roles] : [],
            joinedAt: $user->getMembership()?->joinedAt,
            tenantId: $user->getMembership()?->tenantId,
            createdAt: $user->createdAt()->format(\DateTimeInterface::RFC3339),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'username' => $this->username,
            'email' => $this->email,
            'fullName' => $this->fullName,
            'emailVerified' => $this->emailVerified,
            'createdAt' => $this->createdAt,
            'avatarUrl' => $this->avatarUrl,
            'roles' => $this->roles,
            'permissions' => $this->permissions,
            'joinedAt' => $this->joinedAt,
            'tenantId' => $this->tenantId,
        ];
    }
}
