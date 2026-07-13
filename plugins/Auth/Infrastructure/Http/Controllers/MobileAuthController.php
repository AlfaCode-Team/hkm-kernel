<?php

declare(strict_types=1);

namespace Plugins\Auth\Infrastructure\Http\Controllers;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use Plugins\Auth\Application\Services\MobileAuthService;
use Plugins\OAuth2\Domain\Exceptions\OAuthException;
use Plugins\User\API\Contracts\UserServiceContract;
use Plugins\User\API\DTOs\RegisterUserDTO;
use Project\Http\Controllers\ApiController;
use Project\Http\Controllers\Concerns\InteractsWithAuthManager;

/**
 * MobileAuthController — the old __DEV__ /v1/auth/* endpoints for native clients.
 *
 * Credential verification is a direct call (as in the old mobile controller —
 * password login is not a driver operation), but every TOKEN is issued through
 * the AuthManager front door (`$this->authManager()->issueTokenPair()` /
 * ->issueToken()), the exact parity of the old `AuthManager::issueToken('mobile',
 * …)`. Nothing here reaches into AuthService/RefreshTokenService directly.
 *
 *   POST /auth/mobile/login      { email|identifier, password [, PKCE params] }
 *       PKCE   (client_id set) → 200 { code, state }
 *       legacy (no client_id)  → 200 { user, tokens }
 *   POST /auth/mobile/register   registration fields + optional PKCE params
 *       → 201 { code, state } | 201 { user, tokens }
 *   POST /auth/mobile/logout     (Bearer) → 200 {} — JTI blocklisted.
 *
 * Refresh rotation stays at POST /auth/refresh (AuthTokenController).
 */
final class MobileAuthController extends ApiController
{
    use InteractsWithAuthManager;

    public function __construct(
        private readonly UserServiceContract $users,
        private readonly MobileAuthService $mobile,
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

        $user = $this->users->verifyCredentials($identifier, $password);
        if ($user === null) {
            return Response::unauthorized('Invalid email/username or password.');
        }

        if ($this->wantsPkce($request)) {
            return $this->issueCodeResponse($request, $user->id);
        }

        return $this->ok([
            'user'   => $user->toArray(),
            'tokens' => $this->authManager()->issueTokenPair($user->id, device: $request->header('User-Agent'), ip: $request->ip()),
        ]);
    }

    public function register(): Response
    {
        $request = $this->resolveRequest();

        // Mobile clients register with email only — synthesize the internal
        // username from the email local-part (old flow) when none is sent.
        if (trim((string) $request->input('username', '')) === '') {
            $request = $request->merge(['username' => $this->usernameFromEmail((string) $request->input('email', ''))]);
        }

        $user = $this->mobile->register(RegisterUserDTO::fromRequest($request)); // 422 on bad input

        if ($this->wantsPkce($request)) {
            return $this->issueCodeResponse($request, $user->id, status: 201);
        }

        return $this->created([
            'user'   => $user->toArray(),
            'tokens' => $this->authManager()->issueTokenPair($user->id, device: $request->header('User-Agent'), ip: $request->ip()),
        ]);
    }

    public function logout(): Response
    {
        $this->mobile->revokeAccessToken($this->resolveRequest()->bearerToken());

        return $this->ok([]);
    }

    // ── Internals ───────────────────────────────────────────────────────────────

    private function wantsPkce(Request $request): bool
    {
        return trim((string) $request->input('client_id', '')) !== '';
    }

    private function issueCodeResponse(Request $request, string $userId, int $status = 200): Response
    {
        try {
            $issued = $this->mobile->issueCode($userId, [
                'client_id'             => (string) $request->input('client_id', ''),
                'redirect_uri'          => (string) $request->input('redirect_uri', ''),
                'scope'                 => (string) $request->input('scope', ''),
                'state'                 => (string) $request->input('state', ''),
                'code_challenge'        => (string) $request->input('code_challenge', ''),
                'code_challenge_method' => (string) $request->input('code_challenge_method', ''),
            ]);
        } catch (OAuthException $e) {
            return Response::json(
                ['error' => ['code' => $e->error, 'message' => $e->getMessage()]],
                $e->status,
            );
        }

        return Response::json($issued, $status);
    }

    /** Old flow: email local-part + 4 random hex chars — internal, never exposed. */
    private function usernameFromEmail(string $email): string
    {
        $local = (string) preg_replace('/[^A-Za-z0-9._-]/', '', explode('@', $email)[0] ?? '');
        if (\strlen($local) < 2) {
            $local = 'user';
        }

        return strtolower(substr($local, 0, 42)) . '_' . substr(bin2hex(random_bytes(2)), 0, 4);
    }
}
