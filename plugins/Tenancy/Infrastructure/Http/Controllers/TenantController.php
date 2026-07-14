<?php

declare(strict_types=1);

namespace Plugins\Tenancy\Infrastructure\Http\Controllers;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use Plugins\Auth\API\Contracts\AuthServiceContract;
use Plugins\Tenancy\API\Contracts\MembershipServiceContract;
use Plugins\Tenancy\API\DTOs\TenantSelection;
use Plugins\Tenancy\Domain\Exceptions\NotAMemberException;
use Plugins\User\API\Contracts\TenantProfileReaderContract;
use Project\Http\Controllers\ApiController;

/**
 * Thin HTTP boundary for the tenant-selection flow — DTO/service → Response.
 *
 * COMPOSITION POINT: MembershipService verifies the seat (control plane) and
 * the Auth module mints the tenant-scoped token — tenancy is not
 * authentication, so the two published contracts are composed HERE rather than
 * inside MembershipService (which would also cycle the container graph:
 * AuthService → UserService → MembershipService → AuthService).
 *
 * RequestAware (via ApiController): actions take route params only; the active
 * request is $this->resolveRequest(). The user id always comes from the verified
 * Identity, never from the request body — a client cannot act as another user.
 */
final class TenantController extends ApiController
{
    public function __construct(
        private readonly MembershipServiceContract $memberships,
        private readonly AuthServiceContract $auth,
        // User's published tenant-profile reader — fills the `name` claim
        // (Identity.fullName). Optional and never-throwing: the token simply
        // carries no full name when it is absent or the profile is unreachable.
        private readonly ?TenantProfileReaderContract $profiles = null,
        private readonly int $tokenTtl = 3600,
    ) {}

    /** GET /ajx/me/tenants — the tenant picker for the authenticated user. */
    public function mine(): Response
    {
        $identity = $this->identity();
        if ($identity->isGuest()) {
            return $this->forbidden('Authentication is required.');
        }

        $tenants = $this->memberships->myTenants($identity->userId);

        return $this->ok(['data' => array_map(static fn ($t) => $t->toArray(), $tenants)]);
    }

    /** POST /ajx/tenants/{tenantId}/select — re-mint a tenant-scoped token. */
    public function select(string $tenantId): Response
    {
        $identity = $this->identity();
        if ($identity->isGuest()) {
            return $this->forbidden('Authentication is required.');
        }

        try {
            $seat = $this->memberships->selectTenant(
                $identity->userId,
                $tenantId,
                $this->resolveRequest()->ip(),
            );
        } catch (NotAMemberException) {
            return $this->forbidden('You are not an active member of this tenant.');
        }

        $token = $this->auth->issueJwt(
            $identity->userId,
            [
                'tnt'   => $tenantId,
                'roles' => [$seat->role],
                // Full name lives in the TENANT user_profiles table — selection
                // is the one place tenant context is known at mint time.
                // username/email are filled centrally by AuthService.
                'name'  => $this->profiles?->fullName($identity->userId, $tenantId) ?? '',
            ],
            $this->tokenTtl,
        );

        $selection = new TenantSelection(
            token:     $token,
            tenantId:  $tenantId,
            role:      $seat->role,
            expiresIn: $this->tokenTtl,
        );

        return $this->ok($selection->toArray());
    }
}
