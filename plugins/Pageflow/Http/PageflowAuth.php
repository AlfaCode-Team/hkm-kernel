<?php

declare(strict_types=1);

namespace Plugins\Pageflow\Http;

use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;

/**
 * Controls the identity projection shared to the browser as the `pageflow_auth`
 * prop (UI: useAuth()/<Can>).
 *
 * The DEFAULT exposes only non-sensitive fields (never tokens). Projects that
 * don't want to leak their internal permission vocabulary — or want to send
 * coarse capability booleans instead — register their own projector ONCE at
 * bootstrap:
 *
 *   pageflow_auth_projection(fn(?Identity $id) => [
 *       'userId'        => $id?->userId ?? '',
 *       'authenticated' => $id !== null && !$id->isGuest(),
 *       'canManage'     => (bool) $id?->hasPermission('admin:manage'),
 *   ]);
 *
 * The projector is a definition (a static holder), evaluated per request with
 * the current Identity — safe under OpenSwoole.
 */
final class PageflowAuth
{
    /** @var null|callable(?Identity):array<string,mixed> */
    private static $projector = null;

    /** Override the projection. Pass null to restore the default. */
    public static function project(?callable $projector): void
    {
        self::$projector = $projector;
    }

    /**
     * Resolve the shareable projection for an identity (null => guest).
     *
     * @return array<string,mixed>
     */
    public static function resolve(?Identity $identity): array
    {
        // dd($identity);
        if (self::$projector !== null) {
            return (self::$projector)($identity);
        }

        return self::default($identity);
    }

    /** @return array<string,mixed> */
    private static function default(?Identity $identity): array
    {
        if ($identity === null || $identity->isGuest()) {
            return [
                'userId'        => '',
                'tenantId'      => '',
                'roles'         => [],
                'permissions'   => [],
                'authenticated' => false,
            ];
        }

        return [
            'userId'        => $identity->userId,
            'tenantId'      => $identity->tenantId,
            'roles'         => $identity->roles,
            'permissions'   => $identity->permissions,
            'authenticated' => true,
        ];
    }
}
