<?php

declare(strict_types=1);

namespace Plugins\User\API\DTOs;

use Plugins\User\API\Contracts\UserServiceContract;

/**
 * Outcome of UserServiceContract::verifyEmailByToken(). Carries the VERIFY_*
 * status plus, ONLY for the matched cases (expired / already-verified), the
 * account email — so the HTTP layer can bind a resend cookie to it. The email
 * is populated only when a valid token proved control, and it never travels to
 * the client in the response body (the controller stores a keyed HMAC of it in
 * an encrypted, HttpOnly cookie).
 */
final readonly class VerifyEmailResult
{
    public function __construct(
        public string $status,
        public ?string $email = null,
    ) {
    }

    public static function ok(): self       { return new self(UserServiceContract::VERIFY_OK); }
    public static function invalid(): self   { return new self(UserServiceContract::VERIFY_INVALID); }
    public static function already(string $email): self { return new self(UserServiceContract::VERIFY_ALREADY, $email); }
    public static function expired(string $email): self { return new self(UserServiceContract::VERIFY_EXPIRED, $email); }
}
