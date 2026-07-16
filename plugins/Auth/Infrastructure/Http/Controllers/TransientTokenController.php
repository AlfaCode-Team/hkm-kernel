<?php

declare(strict_types=1);

namespace Plugins\Auth\Infrastructure\Http\Controllers;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use Plugins\Auth\API\Contracts\AuthServiceContract;
use Project\Http\Controllers\ApiController;

/**
 * Transient token — the first-party SPA pattern. GDA-native port of the old
 * __DEV__ Passport TransientTokenController.
 *
 *   POST /auth/token/refresh   (session-authenticated) → a fresh short-lived JWT
 *
 * The caller is already session-authenticated (the `auth` filter + SessionAuthStage
 * establish the Identity), so the request needs no credential body. The issued
 * JWT carries the session identity's roles/permissions, so the SPA can call
 * Bearer-protected APIs with exactly the caller's real grants — the modern,
 * scoped replacement for Passport's "all scopes pass" transient token.
 */
final class TransientTokenController extends ApiController
{
    /** Short lifetime — the SPA refreshes it while the session lives. */
    private const TTL_SECONDS = 900;

    public function __construct(private readonly AuthServiceContract $auth)
    {
    }

    public function refresh(): Response
    {
        $identity = $this->identity();
        if ($identity->isGuest() || $identity->tokenType !== 'session') {
            // Only a real session may mint a transient token (never a Bearer caller).
            return Response::unauthorized('A web session is required.');
        }

        $token = $this->auth->issueJwt(
            $identity->userId,
            [
                'roles'       => $identity->roles,
                'permissions' => $identity->permissions,
                'tnt'         => $identity->tenantId,
                // Carry the session's display identity onto the minted JWT so
                // the SPA's Bearer requests keep username/email/fullName.
                'preferred_username' => $identity->username,
                'email'              => $identity->email,
                'name'               => $identity->fullName,
            ],
            self::TTL_SECONDS,
        );

        return $this->ok([
            'access_token' => $token,
            'token_type'   => 'Bearer',
            'expires_in'   => self::TTL_SECONDS,
        ]);
    }
}
