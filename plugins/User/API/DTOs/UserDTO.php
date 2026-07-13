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
        public ?string $tenantId = null,
        public ?string $joinedAt = null,
    ) {}

    public static function fromEntity(User $user): self
    {
        $roles = $user->getMembership()?->role;
        return new self(
            id:            $user->id(),
            username:      $user->username(),
            email:         $user->email(),
            emailVerified: $user->isEmailVerified(),
            roles:         $roles !== null ? [$roles] : [],
            joinedAt:      $user->getMembership()?->joinedAt,
            tenantId:      $user->getMembership()?->tenantId,
            createdAt:     $user->createdAt()->format(\DateTimeInterface::RFC3339),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id'            => $this->id,
            'username'      => $this->username,
            'email'         => $this->email,
            'emailVerified' => $this->emailVerified,
            'createdAt'     => $this->createdAt,
            'roles'         => $this->roles,
            'joinedAt'     => $this->joinedAt,
            'tenantId'      => $this->tenantId,
        ];
    }
}
