<?php

declare(strict_types=1);

namespace Plugins\Tenancy\API\DTOs;

/**
 * Result of selecting a tenant: a freshly minted tenant-scoped access token
 * (the `tnt` claim is now set) plus the resolved role and expiry. Built by the
 * HTTP boundary (TenantController) after MembershipService verifies the seat
 * and the Auth module mints the token. The client sends this token on
 * subsequent requests; TenantContextStage routes them to the tenant database.
 */
final readonly class TenantSelection
{
    public function __construct(
        public string $token,
        public string $tenantId,
        public string $role,
        public int $expiresIn,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'token'     => $this->token,
            'tokenType' => 'Bearer',
            'tenantId'  => $this->tenantId,
            'role'      => $this->role,
            'expiresIn' => $this->expiresIn,
        ];
    }
}
