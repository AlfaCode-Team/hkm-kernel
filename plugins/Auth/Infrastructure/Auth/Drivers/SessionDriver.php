<?php

declare(strict_types=1);

namespace Plugins\Auth\Infrastructure\Auth\Drivers;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use Plugins\Auth\Application\Auth\AuthUserProxy;
use Plugins\Auth\Application\Ports\Authenticatable;
use Plugins\Auth\Application\Ports\GuardContext;
use Plugins\Auth\Application\Ports\GuardDriver;
use Plugins\Auth\Application\Services\AuthService;

/**
 * SessionDriver — stateful (web/AJAX) guard. Resolves the current user from the
 * session attributes written by AuthService::startSession, enriched into a full
 * proxy via the provider. Roles/permissions/tenant come from the session store.
 */
final class SessionDriver implements GuardDriver
{
    public static function driverName(): string
    {
        return 'session';
    }

    public function resolve(Request $request, GuardContext $context): ?Authenticatable
    {
        $session = $context->session;
        if ($session === null) {
            return null;
        }

        $userId = (string) $session->get(AuthService::SESSION_USER, '');
        if ($userId === '') {
            return null;
        }

       
        $user = $context->provider->retrieveById($userId);
        if (!$user instanceof AuthUserProxy) {
            return $user;
        }

        // Overlay the session-stored security context onto the base proxy.
        return $user->withSecurity(
            $this->stringList($session->get(AuthService::SESSION_ROLES, [])),
            $this->stringList($session->get(AuthService::SESSION_PERMISSIONS, [])),
            (string) $session->get(AuthService::SESSION_TENANT, ''),
            'session',
        );
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        return \is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }
}
