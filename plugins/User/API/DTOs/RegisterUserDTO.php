<?php

declare(strict_types=1);

namespace Plugins\User\API\DTOs;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ValidationException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use Plugins\User\Domain\ValueObjects\Email;
use Plugins\User\Domain\ValueObjects\PasswordPolicy;
use Plugins\User\Domain\ValueObjects\Username;
use Plugins\Validation\AbstractDto;

/**
 * Validated registration input. rules() carry the field SHAPE (mirrored by the
 * Username/Email value objects as defense-in-depth); PasswordPolicy carries the
 * password STRENGTH rules and is merged into the same 422 response.
 *
 * The plaintext password is held only long enough for the service to hash it
 * via the HashingPort, then discarded — it is never persisted or logged.
 */
final readonly class RegisterUserDTO extends AbstractDto
{
    public function __construct(
        public Username $username,
        public Email $email,
        public string $password,
        /**
         * Originating tenant for a self-signup, taken from the request 'tenant'
         * attribute set by Tenancy's TenantContextStage. Opaque string — User
         * stays tenant-agnostic and merely forwards it on the user.registered
         * event so Tenancy can assign membership. '' when there is no tenant.
         */
        public string $tenantId = '',
        /**
         * OPTIONAL tenant-scoped profile fields a public signup may submit in the
         * same request (first_name/last_name/phone/timezone/locale — primitives
         * only). Written asynchronously by a tenant-side listener off the
         * user.registered event. Empty = none.
         *
         * @var array<string, string>
         */
        public array $profile = [],
    ) {}

    /** Profile keys accepted at signup — never trust arbitrary request input. */
    private const PROFILE_FIELDS = [
        'first_name' => 80,
        'last_name'  => 80,
        'phone'      => 15,
        'timezone'   => 50,
        'locale'     => 5,
    ];

    protected static function rules(): array
    {
        return [
            'username' => 'required|string|min:5|max:50|regex:/^[A-Za-z0-9._-]+$/',
            'email'    => 'required|string|email|max:150',
            'password' => 'required|string',
        ];
    }

    protected static function messages(): array
    {
        return [
            'username.min'   => 'Username must be between 5 and 50 characters.',
            'username.max'   => 'Username must be between 5 and 50 characters.',
            'username.regex' => 'Username may only contain letters, digits, dot, underscore and hyphen.',
            'email.email'    => 'Email is not a valid address.',
            'email.max'      => 'Email must be 150 characters or fewer.',
        ];
    }

    public static function fromRequest(Request $request): self
    {
        // Shape errors + password-strength errors combine into one 422.
        $errors = static::collectErrors($request->all());
        $errors += PasswordPolicy::validate((string) $request->input('password', ''));
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return new self(
            username: Username::fromString(trim((string) $request->input('username', ''))),
            email:    Email::fromString(trim((string) $request->input('email', ''))),
            password: (string) $request->input('password', ''),
            tenantId: (string) ($request->attribute('tenant') ?? ''),
            profile:  self::profileFrom($request),
        );
    }

    /** @return array<string, string> only the non-empty, clipped profile fields. */
    private static function profileFrom(Request $request): array
    {
        $profile = [];
        foreach (self::PROFILE_FIELDS as $key => $max) {
            $value = trim((string) $request->input($key, ''));
            if ($value !== '') {
                $profile[$key] = mb_substr($value, 0, $max);
            }
        }

        return $profile;
    }
}
