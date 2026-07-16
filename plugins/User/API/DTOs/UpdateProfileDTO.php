<?php

declare(strict_types=1);

namespace Plugins\User\API\DTOs;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use Plugins\Validation\AbstractDto;

/**
 * Validated profile-update input (idempotent full replace). The user id is NEVER
 * read from the body — it comes from the authenticated Identity in the service.
 *
 * Shape validation is declared in rules() (via the shared Validator); the
 * UserProfile entity re-guards the same rules as defense-in-depth.
 */
final readonly class UpdateProfileDTO extends AbstractDto
{
    public function __construct(
        public ?string $firstName,
        public ?string $lastName,
        public ?string $avatarUrl,
        public ?string $timezone,
        public ?string $locale,
        public ?string $phone,
    ) {}

    protected static function rules(): array
    {
        return [
            'firstName' => 'nullable|string|max:80',
            'lastName'  => 'nullable|string|max:80',
            'avatarUrl' => 'nullable|http_url|max:500',
            'timezone'  => 'nullable|timezone',
            'locale'    => 'nullable|regex:/^[a-z]{2}_[A-Z]{2}$/',
            'phone'     => 'nullable|regex:/^\+?[0-9]{7,15}$/',
        ];
    }

    protected static function messages(): array
    {
        return [
            'locale.regex' => 'Locale must be in ll_CC form, e.g. en_US.',
            'phone.regex'  => 'Phone must be 7–15 digits (optional leading +).',
        ];
    }

    public static function fromRequest(Request $request): self
    {
        static::validated($request); // throws 422 on bad shape

        return new self(
            firstName: self::trimOrNull($request->input('firstName')),
            lastName:  self::trimOrNull($request->input('lastName')),
            avatarUrl: self::trimOrNull($request->input('avatarUrl')),
            timezone:  self::trimOrNull($request->input('timezone')),
            locale:    self::trimOrNull($request->input('locale')),
            phone:     self::trimOrNull($request->input('phone')),
        );
    }

    private static function trimOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }
}
