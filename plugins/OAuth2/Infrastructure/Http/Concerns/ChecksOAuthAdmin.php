<?php

declare(strict_types=1);

namespace Plugins\OAuth2\Infrastructure\Http\Concerns;

use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;

/**
 * Shared OAuth2 admin authorization: a caller is an admin when they hold the
 * OAUTH_ADMIN_ROLE (default "admin") OR their user id is listed in
 * OAUTH_ADMIN_USERS (comma-separated). Used by both the JSON admin API and the
 * server-rendered admin dashboard.
 */
trait ChecksOAuthAdmin
{
    protected function isOAuthAdmin(Identity $identity): bool
    {
        if ($identity->isGuest()) {
            return false;
        }

        $role = (string) (env('OAUTH_ADMIN_ROLE') ?: 'admin');
        if ($role !== '' && $identity->hasRole($role)) {
            return true;
        }

        foreach (explode(',', (string) env('OAUTH_ADMIN_USERS')) as $allowed) {
            $allowed = trim($allowed);
            if ($allowed !== '' && $allowed === $identity->userId) {
                return true;
            }
        }

        return false;
    }
}
