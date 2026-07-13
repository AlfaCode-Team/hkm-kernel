<?php

declare(strict_types=1);

namespace Plugins\User\Domain\Entities;

use Plugins\Tenancy\API\DTOs\TenantSummary;
use Plugins\User\API\IntegrationEvents\UserRegisteredIntegrationEvent;
use Plugins\User\Domain\Events\UserDeletedDomainEvent;
use Plugins\User\Domain\Events\UserRegisteredDomainEvent;
use Plugins\User\Domain\Events\UserUpdatedDomainEvent;
use Plugins\User\Domain\ValueObjects\Email;
use Plugins\User\Domain\ValueObjects\UserId;
use Plugins\User\Domain\ValueObjects\Username;
use Project\Support\Entity\Entity;

/**
 * User aggregate — mirrors the `users` table.
 *
 * Built on the shared {@see Entity} attribute-bag base: state lives in the bag
 * keyed by DB column name, with bidirectional casting through $casts. The
 * Domain layer stays pure — it never hashes a password (that needs the
 * crypto.services HashingPort); the Application service hashes plaintext and
 * passes the already-hashed value in. The hash is $hidden so it never leaks
 * into serialization or var_dump output.
 */
final class User extends Entity
{
    protected string $primaryKey = 'user_id';

    /** @var array<string, string> */
    protected array $casts = [
        'version' => 'int',
        // Entity short-circuits casts on null, so a plain (non-nullable) datetime
        // cast is correct here — a '?datetime' would leak a 'nullable' param into
        // DatetimeCast and be misread as a literal date format.
        'email_verified_at' => 'datetime',
        'email_verification_expires_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /** Credentials + the verification token hash never cross the serialization boundary. */
    protected array $hidden = ['password_hash', 'remember_token', 'email_verification_token_hash'];

 
    protected TenantSummary|null $membership = null;
    

    /**
     * Summary of setMembership
     * @param mixed $membership
     * @return void
     */
    public function setMembership(?TenantSummary $membership): void
    {
        $this->membership = $membership;
    }

    /**
     * Summary of getMembership
     * @return TenantSummary|null
     */
    public function getMembership(): ?TenantSummary
    {
        return $this->membership;
    }

    /**
     * Register a brand-new user. $passwordHash MUST already be a bcrypt hash
     * produced by the HashingPort — never a plaintext password.
     */
    public static function register(
        Username $username,
        Email $email,
        string $passwordHash,
    ): self {
        self::assertBcrypt($passwordHash, 'User must be created with a bcrypt password hash.');

        $id = UserId::generate();
        $createdAt = new \DateTimeImmutable();

        $user = (new self())->forceFill([
            'user_id' => $id->value(),
            'username' => $username->value(),
            'email' => $email->value(),
            'password_hash' => $passwordHash,
            'remember_token' => null,
            'version' => 1,
            'email_verified_at' => null,
            'email_verification_token_hash' => null,
            'email_verification_expires_at' => null,
            'created_at' => $createdAt,
        ]);
        $user->syncOriginal();

        $user->recordEvent(new UserRegisteredDomainEvent(
            userId: $id,
            username: $username,
            email: $email,
            occurredAt: $createdAt,
        ));
       

        return $user;
    }

    public function changeEmail(Email $email): void
    {
        if ($email->value() === $this->email()) {
            return;
        }
        $this->email = $email->value();
        // A new address is unverified until reconfirmed.
        $this->email_verified_at = null;
    }

    public function rename(Username $username): void
    {
        if ($username->value() === $this->username()) {
            return;
        }
        $this->username = $username->value();
    }

    /** Replace the stored credential with a new bcrypt hash. */
    public function changePassword(string $passwordHash): void
    {
        self::assertBcrypt($passwordHash, 'Password must be a bcrypt hash.');
        $this->password_hash = $passwordHash;
        // Any "remember me" sessions are invalidated on credential change.
        $this->remember_token = null;
    }

    /**
     * Mark the email confirmed. Email verification is the account's login gate
     * (see canLogin / UserService::verifyCredentials) — a verified address is
     * what makes the account usable.
     */
    public function verifyEmail(): void
    {
        if ($this->emailVerifiedAt() !== null) {
            return;
        }
        $this->email_verified_at = new \DateTimeImmutable();
        // The token hash + expiry are deliberately KEPT (not nulled): once the
        // account is verified, email_verified_at is the authoritative gate, so a
        // second click of the SAME (still-unexpired) link resolves to the same
        // user and is reported as "already verified" instead of a confusing
        // "invalid link". It confers no new power — verifyEmail() short-circuits
        // above, so the token can never re-verify or mutate state — and it self-
        // expires at its original TTL. Do NOT re-null these here.
    }

    /**
     * Arm a pending email-verification token. Stores only the SHA-256 HASH of
     * the emailed token (the raw token lives only in the email) plus a hard
     * expiry. Re-arming replaces any previous pending token.
     */
    public function startEmailVerification(string $tokenHash, \DateTimeImmutable $expiresAt): void
    {
        $this->email_verification_token_hash = $tokenHash;
        $this->email_verification_expires_at = $expiresAt;
    }

    public function emailVerificationTokenHash(): ?string
    {
        $v = $this->getRawAttribute('email_verification_token_hash');
        return $v === null ? null : (string) $v;
    }

    public function emailVerificationExpiresAt(): ?\DateTimeImmutable
    {
        return $this->getDate('email_verification_expires_at');
    }

    /**
     * Record a single consolidated update event for the fields mutated so far.
     * Bumps the optimistic-lock version. Returns true when something actually
     * changed. Call after applying edits, before persisting.
     */
    public function commitChanges(): bool
    {
        $changed = $this->getChanges();
        if ($changed === []) {
            return false;
        }

        $this->version = $this->version() + 1;

        $this->recordEvent(new UserUpdatedDomainEvent(
            userId: UserId::fromString($this->id()),
            changed: array_values(array_unique($changed)),
            occurredAt: new \DateTimeImmutable(),
        ));

        
        $this->syncOriginal();

        return true;
    }

    /** Record the (soft-)deletion event. */
    public function markDeleted(): void
    {
        $this->recordEvent(new UserDeletedDomainEvent(
            userId: UserId::fromString($this->id()),
            occurredAt: new \DateTimeImmutable(),
        ));
    }


    /** Store the SHA-256 of a "remember me" token (never the raw token). */
    public function setRememberTokenHash(?string $sha256Hash): void
    {
        $this->remember_token = $sha256Hash;
    }

    private static function assertBcrypt(string $hash, string $message): void
    {
        // bcrypt output is always exactly 60 chars and starts with $2y$/$2a$/$2b$.
        if (strlen($hash) !== 60 || !str_starts_with($hash, '$2')) {
            throw new \DomainException($message);
        }
    }

    // ─── scalar accessors (replace the former Value-Object getters) ──────────

    public function id(): string
    {
        return $this->getString('user_id');
    }
    public function username(): string
    {
        return $this->getString('username');
    }
    public function email(): string
    {
        return $this->getString('email');
    }
    public function version(): int
    {
        return $this->getInt('version');
    }
    public function createdAt(): \DateTimeImmutable
    {
        return $this->getDate('created_at') ?? new \DateTimeImmutable();
    }
    public function emailVerifiedAt(): ?\DateTimeImmutable
    {
        return $this->getDate('email_verified_at');
    }
    public function isEmailVerified(): bool
    {
        return $this->emailVerifiedAt() !== null;
    }

    /** A verified email is the login gate (replaces the old status column). */
    public function canLogin(): bool
    {
        return $this->emailVerifiedAt() !== null;
    }

    /** Persistence-only accessors — never serialise these into a response. */
    public function passwordHash(): string
    {
        return $this->getString('password_hash');
    }
    public function rememberToken(): ?string
    {
        $v = $this->getRawAttribute('remember_token');
        return $v === null ? null : (string) $v;
    }
}
