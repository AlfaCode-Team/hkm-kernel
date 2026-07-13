<?php

declare(strict_types=1);

namespace Plugins\SocialAuth\Application\Services;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ServiceException;
use Plugins\SocialAuth\Infrastructure\Persistence\SocialIdentityRepository;
use Plugins\User\API\Contracts\UserServiceContract;
use Plugins\User\API\DTOs\RegisterUserDTO;
use Plugins\User\API\DTOs\UserDTO;
use Plugins\User\Domain\ValueObjects\Email;
use Plugins\User\Domain\ValueObjects\Username;

/**
 * SocialLoginService — turns a verified provider profile into a platform user
 * (the "findOrCreateOAuthUser" the old __DEV__ flow stubbed out).
 *
 * Resolution order:
 *   1. linked identity (provider + provider_user_id) → existing user
 *   2. provider-verified email matches an existing user → LINK + return
 *   3. no match → CREATE (random strong password, email auto-verified since the
 *      provider asserted it) + LINK
 *
 * A profile with no email can only match an existing link (1) — creating or
 * linking by email is impossible, so it fails with a clear error.
 */
final class SocialLoginService
{
    public function __construct(
        private readonly SocialIdentityRepository $identities,
        private readonly UserServiceContract $users,
    ) {
    }

    /**
     * @param array{id:string,email:?string,name:?string,nickname:?string,avatar:?string} $profile
     */
    public function resolveUser(string $provider, array $profile): UserDTO
    {
        $providerUserId = trim((string) ($profile['id'] ?? ''));
        if ($providerUserId === '') {
            throw new ServiceException('social_auth.profile.missing_id', layer: 'service.social_auth');
        }

        $email  = $this->normalizedEmail($profile['email'] ?? null);
        $name   = $profile['name'] ?? null;
        $avatar = $profile['avatar'] ?? null;

        // 1 — already linked.
        $userId = $this->identities->findUserId($provider, $providerUserId);
        if ($userId !== null) {
            $user = $this->users->find($userId);
            if ($user !== null) {
                // Refresh the provider snapshot (best-effort).
                $this->identities->link($provider, $providerUserId, $userId, $email, $name, $avatar);

                return $user;
            }
        }

        if ($email === null) {
            throw new ServiceException(
                'social_auth.profile.missing_email',
                layer:   'service.social_auth',
                context: ['provider' => $provider],
            );
        }

        // 2 — link to the existing account behind the provider-verified email.
        $user = $this->users->findByIdentifier($email);

        // 3 — first sign-in: create the account.
        $user ??= $this->createUser($email, $name, $profile['nickname'] ?? null);

        $this->identities->link($provider, $providerUserId, $user->id, $email, $name, $avatar);

        return $user;
    }

    // ── Internals ───────────────────────────────────────────────────────────────

    private function createUser(string $email, ?string $name, ?string $nickname): UserDTO
    {
        $profile = [];
        if (\is_string($name) && trim($name) !== '') {
            $parts = preg_split('/\s+/', trim($name), 2) ?: [];
            $profile['first_name'] = mb_substr($parts[0] ?? '', 0, 80);
            if (($parts[1] ?? '') !== '') {
                $profile['last_name'] = mb_substr($parts[1], 0, 80);
            }
        }

        $dto = new RegisterUserDTO(
            username: Username::fromString($this->usernameFor($nickname, $email)),
            email:    Email::fromString($email),
            // Social accounts have no local password — mint an unguessable one.
            // The user can set a real one later through the reset flow.
            password: 'A1!' . bin2hex(random_bytes(24)),
            profile:  $profile,
        );

        $verificationToken = $this->users->registerPublic($dto);

        // The provider already verified this mailbox — activate immediately.
        try {
            $this->users->verifyEmailByToken($verificationToken);
        } catch (\Throwable) {
            // Non-fatal: the account just stays pending verification.
        }

        $user = $this->users->findByIdentifier($email,true);
        if ($user === null) {
            throw new ServiceException('social_auth.register.lookup_failed', layer: 'service.social_auth');
        }

        return $user;
    }

    /** nickname (sanitised) or email local-part, + 4 random hex chars. */
    private function usernameFor(?string $nickname, string $email): string
    {
        $base = \is_string($nickname) ? (string) preg_replace('/[^A-Za-z0-9._-]/', '', $nickname) : '';
        if (\strlen($base) < 2) {
            $base = (string) preg_replace('/[^A-Za-z0-9._-]/', '', explode('@', $email)[0] ?? '');
        }
        if (\strlen($base) < 2) {
            $base = 'user';
        }

        return strtolower(substr($base, 0, 42)) . '_' . substr(bin2hex(random_bytes(2)), 0, 4);
    }

    private function normalizedEmail(mixed $email): ?string
    {
        if (!\is_string($email)) {
            return null;
        }

        $email = mb_strtolower(trim($email));

        return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null;
    }
}
