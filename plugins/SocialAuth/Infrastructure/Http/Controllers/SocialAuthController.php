<?php

declare(strict_types=1);

namespace Plugins\SocialAuth\Infrastructure\Http\Controllers;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\GatewayException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ServiceException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\SessionPort;
use Plugins\Auth\API\Contracts\AuthServiceContract;
use Plugins\Auth\API\Contracts\RefreshTokenServiceContract;
use Plugins\Session\Infrastructure\Http\StartSessionStage;
use Plugins\SocialAuth\API\Contracts\SocialAuthServiceContract;
use Plugins\SocialAuth\Application\Services\SocialLoginService;
use Plugins\SocialAuth\Infrastructure\Gateways\ProviderTokenGateway;
use Plugins\SocialAuth\Socialite\Ports\User as SocialUser;
use Plugins\User\API\DTOs\UserDTO;
use Project\Http\Controllers\ApiController;

/**
 * SocialAuthController — social sign-in, end to end.
 *
 *   GET  /auth/social/{driver}           → 302 to the provider's consent page
 *   GET  /auth/social/{driver}/callback  → provider round-trip:
 *          default      → platform session login + redirect (web flow)
 *          ?mode=token  → 200 { user, tokens } (SPA/native webview flow)
 *   POST /auth/social/{driver}/token     → native-SDK token sign-in (mobile):
 *          { access_token | id_token | identity_token[, name] }
 *          → 200 { user, tokens }
 *
 * The provider profile is resolved to a platform user by SocialLoginService
 * (linked identity → email match → create); tokens come from the Auth plugin's
 * published contracts, so the resulting credentials are indistinguishable from
 * a password login.
 */
final class SocialAuthController extends ApiController
{
    public function __construct(
        private readonly SocialAuthServiceContract $social,
        private readonly SocialLoginService $login,
        private readonly ProviderTokenGateway $tokens,
        private readonly AuthServiceContract $auth,
        private readonly RefreshTokenServiceContract $refreshTokens,
        private readonly SessionPort $session,
        private readonly int $accessTtl = 3600,
        private readonly string $successRedirect = '/',
    ) {
    }

    public function redirect(string $driver): Response
    {
        try {
            return Response::redirect($this->social->redirectUrl($driver));
        } catch (ServiceException) {
            return $this->notFound("Unknown or unconfigured social provider [{$driver}].");
        }
    }

    public function callback(string $driver): Response
    {
        $request = $this->resolveRequest();

        try {
            $profile = $this->social->userFromCallback($driver, $request);
            $user    = $this->login->resolveUser($driver, $this->profileArray($profile));
        } catch (ServiceException $e) {
            return $this->socialFailure($e);
        }

        if ($request->input('mode') === 'token' || $request->expectsJson()) {
            return $this->ok(['user' => $user->toArray(), 'tokens' => $this->issueTokenPair($user)]);
        }

        // Web flow: open a platform session and send the browser on its way —
        // back to the page recorded by the Session plugin's StartSessionStage
        // when there is one (validated: relative path only — open-redirect
        // guard), else the configured default.
        $this->auth->startSession($this->session, $user->id, username: $user->username, email: $user->email);

        $previous = $this->session->pull(StartSessionStage::PREVIOUS_URL);
        $target   = is_string($previous)
            && $previous !== ''
            && $previous[0] === '/'
            && !str_starts_with($previous, '//')
            && !str_starts_with($previous, '/\\')
                ? $previous
                : $this->successRedirect;

        return Response::redirect($target);
    }

    public function token(string $driver): Response
    {
        $request = $this->resolveRequest();

        try {
            $profile = $this->tokens->verify($driver, [
                'access_token'   => (string) $request->input('access_token', ''),
                'id_token'       => (string) $request->input('id_token', ''),
                'identity_token' => (string) $request->input('identity_token', ''),
                'name'           => (string) $request->input('name', ''),
            ]);
            $user = $this->login->resolveUser($driver, $profile);
        } catch (GatewayException $e) {
            return Response::unauthorized($e->getMessage());
        } catch (ServiceException $e) {
            return $this->socialFailure($e);
        }

        return $this->ok(['user' => $user->toArray(), 'tokens' => $this->issueTokenPair($user)]);
    }

    // ── Internals ───────────────────────────────────────────────────────────────

    /** @return array<string,mixed> the old api.md `tokens` shape */
    private function issueTokenPair(UserDTO $user): array
    {
        $request = $this->resolveRequest();
        $refresh = $this->refreshTokens->issue(
            $user->id,
            device: $request->header('User-Agent'),
            ip:     $request->ip(),
        );

        return [
            // Display claims passed explicitly — the user record is already in
            // hand, so AuthService skips its central-lookup enrichment.
            'accessToken'      => $this->auth->issueJwt($user->id, [
                'preferred_username' => $user->username,
                'email'              => $user->email,
            ], $this->accessTtl),
            'tokenType'        => 'Bearer',
            'expiresAt'        => time() + $this->accessTtl,
            'refreshToken'     => $refresh->token,
            'refreshExpiresAt' => $refresh->expiresAt,
        ];
    }

    /** @return array{id:string,email:?string,name:?string,nickname:?string,avatar:?string} */
    private function profileArray(SocialUser $user): array
    {
        return [
            'id'       => (string) $user->getId(),
            'email'    => $user->getEmail(),
            'name'     => $user->getName(),
            'nickname' => $user->getNickname(),
            'avatar'   => $user->getAvatar(),
        ];
    }

    private function socialFailure(ServiceException $e): Response
    {
        $message = match ($e->getMessage()) {
            'social_auth.profile.missing_email' =>
                'This provider account has no verified email — sign in with a provider that shares one, or register first.',
            default => 'Social sign-in failed. Please try again.',
        };

        return Response::json(['error' => ['code' => $e->getMessage(), 'message' => $message]], 422);
    }
}
