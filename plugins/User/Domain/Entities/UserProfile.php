<?php

declare(strict_types=1);

namespace Plugins\User\Domain\Entities;

use Project\Support\Entity\Entity;

/**
 * UserProfile — mirrors the TENANT-scoped `user_profiles` table (one row per
 * user). `userId` is the central ULID (users.user_id); the row lives in the
 * submitter's tenant database, no cross-DB foreign key.
 *
 * Built on the shared {@see Entity} attribute-bag base, keyed by DB column.
 * Invariants (lengths, locale shape, timezone validity) are enforced in the
 * input named constructors so an invalid profile can never be built from
 * request input; the Update DTO maps raw request input onto fromInput().
 * reconstitute() trusts already-persisted rows and skips re-validation.
 */
final class UserProfile extends Entity
{
    private const DEFAULT_TIMEZONE = 'UTC';
    private const DEFAULT_LOCALE   = 'en_US';
    private const DEFAULT_PHONE    = '0700000000';

    protected string $primaryKey = 'user_id';

    /** Build from validated input (the Update DTO). */
    public static function fromInput(
        string $userId,
        ?string $firstName,
        ?string $lastName,
        ?string $avatarUrl,
        ?string $timezone,
        ?string $locale,
        ?string $phone,
    ): self {
        return self::guarded([
            'user_id'    => $userId,
            'first_name' => self::nullIfBlank($firstName),
            'last_name'  => self::nullIfBlank($lastName),
            'avatar_url' => self::nullIfBlank($avatarUrl),
            'timezone'   => self::nullIfBlank($timezone) ?? self::DEFAULT_TIMEZONE,
            'locale'     => self::nullIfBlank($locale) ?? self::DEFAULT_LOCALE,
            'phone'      => self::nullIfBlank($phone) ?? self::DEFAULT_PHONE,
        ]);
    }

    /** The defaults returned when a user has no profile row yet. */
    public static function defaults(string $userId): self
    {
        return self::guarded([
            'user_id'    => $userId,
            'first_name' => null,
            'last_name'  => null,
            'avatar_url' => null,
            'timezone'   => self::DEFAULT_TIMEZONE,
            'locale'     => self::DEFAULT_LOCALE,
            'phone'      => self::DEFAULT_PHONE,
        ]);
    }

    /** @param array<string,mixed> $attrs Validate, then hydrate the bag. */
    private static function guarded(array $attrs): self
    {
        $userId = (string) $attrs['user_id'];
        if ($userId === '' || mb_strlen($userId) > 31) {
            throw new \DomainException('UserProfile requires a valid user id.');
        }
        self::guardOptional($attrs['first_name'], 80, 'First name');
        self::guardOptional($attrs['last_name'], 80, 'Last name');
        $avatarUrl = $attrs['avatar_url'];
        if ($avatarUrl !== null) {
            if (mb_strlen($avatarUrl) > 500 || !filter_var($avatarUrl, FILTER_VALIDATE_URL)) {
                throw new \DomainException('Avatar URL must be a valid URL.');
            }
            $scheme = strtolower((string) parse_url($avatarUrl, PHP_URL_SCHEME));
            if ($scheme !== 'http' && $scheme !== 'https') {
                throw new \DomainException('Avatar URL must use http or https.');
            }
        }
        if (!in_array($attrs['timezone'], timezone_identifiers_list(), true)) {
            throw new \DomainException('Unknown timezone.');
        }
        if (!preg_match('/^[a-z]{2}_[A-Z]{2}$/', (string) $attrs['locale'])) {
            throw new \DomainException('Locale must be in ll_CC form, e.g. en_US.');
        }
        if (!preg_match('/^\+?[0-9]{7,15}$/', (string) $attrs['phone'])) {
            throw new \DomainException('Phone must be 7–15 digits (optional leading +).');
        }

        $p = (new self())->forceFill($attrs);
        $p->syncOriginal();

        return $p;
    }

    private static function guardOptional(?string $value, int $max, string $label): void
    {
        if ($value !== null && mb_strlen($value) > $max) {
            throw new \DomainException("{$label} cannot exceed {$max} characters.");
        }
    }

    private static function nullIfBlank(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);
        return ($value === null || $value === '') ? null : $value;
    }

    public function userId(): string     { return $this->getString('user_id'); }
    public function firstName(): ?string { return $this->nullable('first_name'); }
    public function lastName(): ?string  { return $this->nullable('last_name'); }
    public function avatarUrl(): ?string { return $this->nullable('avatar_url'); }
    public function timezone(): string   { return $this->getString('timezone'); }
    public function locale(): string     { return $this->getString('locale'); }
    public function phone(): string      { return $this->getString('phone'); }

    public function fullName(): string
    {
        $first = trim((string) $this->firstName());
        $last  = trim((string) $this->lastName());
        return trim("{$first} {$last}");
    }
    private function nullable(string $key): ?string
    {
        $v = $this->getRawAttribute($key);
        return $v === null ? null : (string) $v;
    }

    /** @return array<string, mixed> Camel-cased API shape (not the DB shape). */
    public function toArray(bool $onlyChanged = false): array
    {
        return [
            'userId'    => $this->userId(),
            'firstName' => $this->firstName(),
            'lastName'  => $this->lastName(),
            'avatarUrl' => $this->avatarUrl(),
            'timezone'  => $this->timezone(),
            'locale'    => $this->locale(),
            'phone'     => $this->phone(),
        ];
    }
}
