<?php

declare(strict_types=1);

namespace Plugins\Auth\Application\Auth;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\CachePort;
use Plugins\Auth\API\Contracts\RefreshTokenServiceContract;
use Plugins\Auth\Application\Ports\PasswordBroker;
use Plugins\Auth\Application\Services\DeviceSessionService;
use Plugins\User\API\Contracts\UserServiceContract;

/**
 * PasswordResetBroker — CachePort-backed password reset broker.
 *
 * Reset tokens are stored as a SHA-256 hash keyed by the account email, with an
 * absolute TTL; only the plaintext token (returned once from sendResetLink) can
 * satisfy the check. Token comparison is constant-time. A throttle key prevents
 * rapid re-issuance. No token table needed — the cache is the store.
 */
final class PasswordResetBroker implements PasswordBroker
{
    private const TOKEN_PREFIX    = 'auth:pwreset:tok:';
    private const THROTTLE_PREFIX = 'auth:pwreset:thr:';
    private const OTP_PREFIX      = 'auth:pwreset:otp:';
    private const ATTEMPT_PREFIX  = 'auth:pwreset:try:';

    public function __construct(
        private readonly UserServiceContract $users,
        private readonly CachePort $cache,
        private readonly int $ttlSeconds = 3600,
        private readonly int $throttleSeconds = 60,
        private readonly int $otpTtlSeconds = 600,
        /** Wrong-code guesses allowed per account before the OTP is burned. */
        private readonly int $maxOtpAttempts = 5,
        /** Optional — when bound, a reset revokes every refresh token. */
        private readonly ?RefreshTokenServiceContract $refreshTokens = null,
        /** Optional — when bound, a reset revokes every device session. */
        private readonly ?DeviceSessionService $devices = null,
    ) {}

    public function sendResetLink(string $email): array
    {
        $email = mb_strtolower(trim($email));

        $user = $this->users->findByIdentifier($email);
        if ($user === null) {
            return ['status' => self::INVALID_USER];
        }

        if ($this->cache->has(self::THROTTLE_PREFIX . $this->key($email))) {
            return ['status' => self::THROTTLED];
        }

        $token = bin2hex(random_bytes(32));
        $this->cache->set(self::TOKEN_PREFIX . $this->key($email), hash('sha256', $token), $this->ttlSeconds);
        $this->cache->set(self::THROTTLE_PREFIX . $this->key($email), 1, $this->throttleSeconds);

        return [
            'status' => self::RESET_LINK_SENT,
            'token'  => $token,
            'userId' => $user->id,
            'email'  => $email,
        ];
    }

    public function validateToken(string $email, string $token): bool
    {
        $email  = mb_strtolower(trim($email));
        $stored = $this->cache->get(self::TOKEN_PREFIX . $this->key($email));

        return is_string($stored) && $stored !== '' && hash_equals($stored, hash('sha256', $token));
    }

    public function reset(string $email, string $token, string $newPassword): string
    {
        $email = mb_strtolower(trim($email));

        if (!$this->validateToken($email, $token)) {
            return self::INVALID_TOKEN;
        }

        $user = $this->users->findByIdentifier($email);
        if ($user === null) {
            return self::INVALID_USER;
        }

        if (!$this->users->resetPassword($user->id, $newPassword)) {
            return self::INVALID_USER;
        }

        // A reset is a compromise-recovery action, so every credential minted
        // under the OLD password has to die with it — otherwise an attacker who
        // is already signed in (or holds a refresh token) keeps access and the
        // reset achieves nothing. UserService clears the remember-me token;
        // refresh tokens and device sessions are Auth's own and are swept here.
        //
        // Deliberately BEFORE the token burn: if a sweep fails we do not report
        // success, and the reset token stays valid so the user can retry. The
        // password write is idempotent, so a retry is harmless.
        $this->refreshTokens?->revokeAllForUser($user->id);
        $this->devices?->revokeAll($user->id);

        // One-time use: burn the token (and the throttle) on success.
        $this->cache->delete(self::TOKEN_PREFIX . $this->key($email));
        $this->cache->delete(self::THROTTLE_PREFIX . $this->key($email));
        // Also burn any OTP still outstanding for this account — the reset is
        // done, so a code sitting in an inbox must not open a second window.
        $this->cache->delete(self::OTP_PREFIX . $this->key($email));

        return self::PASSWORD_RESET;
    }

    // ── OTP mode (old __DEV__ mobile forgot-password flow) ──────────────────────

    public function sendOtp(string $email): ?array
    {
        $result = $this->sendResetLink($email);
        if (($result['status'] ?? '') !== self::RESET_LINK_SENT || !isset($result['token'])) {
            return null; // unknown user or throttled — caller responds generically
        }

        // 6-digit OTP paired with the underlying reset token: "otp|token",
        // short-lived and single-use (consumed by verifyOtp).
        $otp = str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);
        $this->cache->set(
            self::OTP_PREFIX . $this->key((string) $result['email']),
            $otp . '|' . $result['token'],
            $this->otpTtlSeconds,
        );
        // A newly issued code starts with a clean guess budget.
        $this->cache->delete(self::ATTEMPT_PREFIX . $this->key((string) $result['email']));

        return ['otp' => $otp, 'email' => (string) $result['email']];
    }

    public function verifyOtp(string $email, string $otp): ?string
    {
        $email  = mb_strtolower(trim($email));
        $key    = $this->key($email);
        $cached = $this->cache->get(self::OTP_PREFIX . $key);

        if (!is_string($cached) || $cached === '') {
            return null;
        }

        [$storedOtp, $token] = explode('|', $cached, 2) + [1 => ''];
        if ($token === '' || !hash_equals($storedOtp, trim($otp))) {
            // Cap guesses PER ACCOUNT. The route throttle is per IP, and the code
            // space is only 10^6 — a distributed guesser would otherwise get
            // effectively unlimited tries inside the OTP's whole lifetime.
            $attempts = $this->cache->increment(self::ATTEMPT_PREFIX . $key);

            if ($attempts === 1) {
                // Re-set with a TTL so a stale counter can never outlive the code
                // and burn a LATER, legitimate OTP. increment() carries no TTL.
                $this->cache->set(self::ATTEMPT_PREFIX . $key, 1, $this->otpTtlSeconds);
            }

            if ($attempts >= $this->maxOtpAttempts) {
                $this->cache->delete(self::OTP_PREFIX . $key);
                $this->cache->delete(self::ATTEMPT_PREFIX . $key);
            }

            return null;
        }

        // Single-use: burn the OTP; the underlying token stays valid for reset().
        $this->cache->delete(self::OTP_PREFIX . $key);
        $this->cache->delete(self::ATTEMPT_PREFIX . $key);

        return $token;
    }

    private function key(string $email): string
    {
        return hash('sha256', $email);
    }
}
