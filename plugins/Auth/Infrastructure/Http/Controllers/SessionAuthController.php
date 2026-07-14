<?php

declare(strict_types=1);

namespace Plugins\Auth\Infrastructure\Http\Controllers;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use Plugins\Auth\Application\Ports\Authenticatable;
use Plugins\Auth\Application\Services\DeviceSessionService;
use Plugins\Session\Infrastructure\Http\StartSessionStage;
use Project\Http\Controllers\ApiController;
use Project\Http\Controllers\Concerns\InteractsWithAuthManager;

/**
 * Stateful (session) login/logout for browser + AJAX clients.
 *
 * Everything runs through the AuthManager guard — `$this->auth('web')` — exactly
 * the old `$auth->guard('web')->attempt()/logout()` ergonomic. The 'web' guard's
 * driver owns credential verification, the session write, remember-me, and the
 * device-session registry; this controller only translates request → guard call
 * → Response. Incoming-token verification (for token/JWT callers) still happens
 * in the SecurityGateway before modules load — that is the one thing the guard
 * cannot own under GDA.
 *
 *   POST   /auth/login                { identifier|email, password, remember? }
 *   POST   /auth/logout               → 204
 *   GET    /auth/me                   → current identity
 *   GET    /auth/sessions             → active device sessions
 *   DELETE /auth/sessions/{id}        → revoke one device
 *   POST   /auth/logout-other-devices { password } → revoke all OTHER devices
 *
 * CSRF: session/cookie endpoints (NOT under /api) are guarded by the kernel's
 * CsrfTokenLayer — send the token via `X-CSRF-Token` (AJAX) or `_csrf_token`.
 */
final class SessionAuthController extends ApiController
{
    use InteractsWithAuthManager;

    public function __construct(
        // Only the device-management endpoints (list / revoke-by-id) touch the
        // registry directly; the login/logout flow goes through the guard.
        private readonly ?DeviceSessionService $devices = null,
    ) {
    }

    public function login(): Response
    {
        $request    = $this->resolveRequest();
        $identifier = trim((string) ($request->input('identifier') ?? $request->input('email')));
        $password   = (string) $request->input('password');

        if ($identifier === '' || $password === '') {
            return $this->unprocessable([
                'identifier' => $identifier === '' ? 'An email or username is required.' : '',
                'password'   => $password === '' ? 'A password is required.' : '',
            ]);
        }

        $guard = $this->auth('web');

        
        // The 'web' guard's driver verifies credentials, opens the session,
        // binds the device fingerprint + auth_sessions row, and (when asked)
        // queues the remember-me recaller — all internally.
        if (!$guard->attempt($this->credentials($identifier, $password), $request->boolean('remember'))) {
            // Uniform message — never reveals whether the account exists or is locked.
            return Response::unauthorized('Invalid credentials.');
        }

        // Where to send the user after sign-in, first match wins:
        //   1. an explicit `redirectTo` on the login request itself (query or
        //      body — e.g. /auth/login?redirectTo=/billing),
        //   2. the previous page recorded by the Session plugin's
        //      StartSessionStage — PULLED (one-time) either way, so the
        //      fulfilled intent never goes stale,
        //   3. '/'.
        // Both candidates pass the same open-redirect guard.
        $previous = $this->sessionPull(StartSessionStage::PREVIOUS_URL);
        $redirect = $this->safeRedirect($request->input('redirectTo'))
            ?? $this->safeRedirect($previous)
            ?? '/';

        // Browser form POST → real redirect; AJAX/SPA callers get the target in
        // the payload and navigate client-side.
        if (!$request->expectsJson()) {
            return Response::redirect($redirect);
        }

        return $this->ok(['user' => $this->shape($guard->user()), 'redirectTo' => $redirect]);
    }

    /**
     * Validate a redirect candidate into a safe INTERNAL target, or null when
     * it is unusable. Accepts only a relative path ('/…'); rejects
     * protocol-relative ('//…'), backslash tricks and absolute URLs — the
     * open-redirect guard for both the request param and the session value.
     */
    private function safeRedirect(mixed $candidate): ?string
    {
        if (!is_string($candidate) || $candidate === '' || $candidate[0] !== '/'
            || str_starts_with($candidate, '//') || str_starts_with($candidate, '/\\')) {
            return null;
        }

        return $candidate;
    }

    public function logout(): Response
    {
        // Guard tears down the session, revokes this device's registry row, and
        // clears the remember-me token + cookie.
        $this->auth('web')->logout();

        return $this->noContent();
    }

    public function me(): Response
    {
        // Reflects HOWEVER the request is authenticated (session OR a verified
        // token attached by the SecurityGateway), so read the request Identity.
        $identity = $this->identity();
        if ($identity->isGuest()) {
            return Response::unauthorized('Not authenticated.');
        }

        return $this->ok([
            'userId'      => $identity->userId,
            'username'    => $identity->username,
            'email'       => $identity->email,
            'fullName'    => $identity->fullName,
            'tenantId'    => $identity->tenantId,
            'roles'       => $identity->roles,
            'permissions' => $identity->permissions,
            'via'         => $identity->tokenType,
        ]);
    }

    // ── Device sessions ("see & sign out my devices") ───────────────────────────

    public function sessions(): Response
    {
        if ($this->devices === null) {
            return $this->ok(['sessions' => []]);
        }

        return $this->ok([
            'sessions' => $this->devices->listDevices($this->identity()->userId, $this->guardSession()),
        ]);
    }

    public function revokeSession(string $id): Response
    {
        if ($this->devices === null || !$this->devices->revokeById($this->identity()->userId, $id)) {
            return $this->notFound('No such session.');
        }

        return $this->noContent();
    }

    public function logoutOtherDevices(): Response
    {
        $password = (string) $this->resolveRequest()->input('password');
        if ($password === '') {
            return $this->unprocessable(['password' => 'Your current password is required.']);
        }

        // Guard re-verifies the password, revokes every OTHER device's session,
        // and rotates the remember token (reissuing this device's cookie).
        $user = $this->auth('web')->logoutOtherDevices($password);
        if ($user === null) {
            return Response::unauthorized('Password confirmation failed.');
        }

        return $this->ok(['message' => 'Signed out of all other devices.']);
    }

    // ── Internals ───────────────────────────────────────────────────────────────

    /**
     * Credential map: the identifier is offered under every lookup field the
     * ModelUserProvider tries (identifier/email/username), so a single input
     * works whether the user typed an email or a username.
     *
     * @return array<string,string>
     */
    private function credentials(string $identifier, string $password): array
    {
        return [
            'identifier' => $identifier,
            'email'      => $identifier,
            'username'   => $identifier,
            'password'   => $password,
        ];
    }

    /** @return array<string,string> */
    private function shape(?Authenticatable $user): array
    {
        if ($user === null) {
            return [];
        }

        return [
            'id'       => $user->getAuthIdentifier(),
            'email'    => $user->getEmail(),
            'username' => $user->getUsername(),
        ];
    }

    /** The active session store, for flagging the caller's own device in listDevices(). */
    private function guardSession(): ?\AlfacodeTeam\PhpServicePlatform\Kernel\Ports\SessionPort
    {
        $container = $this->resolveRequest()->container();

        return $container !== null && $container->has(\AlfacodeTeam\PhpServicePlatform\Kernel\Ports\SessionPort::class)
            ? $container->make(\AlfacodeTeam\PhpServicePlatform\Kernel\Ports\SessionPort::class)
            : null;
    }
}
