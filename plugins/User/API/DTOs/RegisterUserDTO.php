<?php

declare(strict_types=1);

namespace Plugins\User\API\DTOs;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ValidationException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use Plugins\User\Domain\ValueObjects\Email;
use Plugins\User\Domain\ValueObjects\PasswordPolicy;
use Plugins\User\Domain\ValueObjects\Username;
use Plugins\Validation\AbstractDto;
use Plugins\Validation\Validator;

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
    ) {
    }

    /** Profile keys accepted at signup — never trust arbitrary request input. */
    private const PROFILE_FIELDS = [
        'first_name' => 80,
        'last_name' => 80,
        'phone' => 15,
        'timezone' => 50,
        'locale' => 5,
    ];

    protected static function rules(): array
    {
        return [
            'username' => 'required|string|min:5|max:50|regex:/^[A-Za-z0-9._-]+$/',
            'email' => 'required|string|email|max:150',
            'password' => 'required|string',
        ];
    }

    protected static function messages(): array
    {
        return [
            'username.min' => 'Username must be between 5 and 50 characters.',
            'username.max' => 'Username must be between 5 and 50 characters.',
            'username.regex' => 'Username may only contain letters, digits, dot, underscore and hyphen.',
            'email.email' => 'Email is not a valid address.',
            'email.max' => 'Email must be 150 characters or fewer.',
        ];
    }

    /**
     * Validation for the OPTIONAL profile fields. Every rule is `nullable`, so
     * an absent field passes; a present one must match. Uses only CORE Validator
     * rules (no CommonRules registration needed). Keys mirror PROFILE_FIELDS.
     *
     * @return array<string, string>
     */
    private static function profileRules(): array
    {
        return [
            'first_name' => "nullable|string|max:80|regex:/^[\\p{L}\\p{M} .,'\\-]+$/u",
            'last_name'  => "nullable|string|max:80|regex:/^[\\p{L}\\p{M} .,'\\-]+$/u",
            'phone'      => 'nullable|string|max:15|regex:/^[0-9+()\\s-]+$/',
            'timezone'   => 'nullable|string|timezone',
            'locale'     => 'nullable|string|max:5|regex:/^[A-Za-z]{2}([_-][A-Za-z]{2})?$/',
        ];
    }

    public static function fromRequest(Request $request): self
    {
        // Shape errors + password-strength errors + profile-field errors all
        // combine into one 422. Profile is validated on the ASSEMBLED array so a
        // derived full_name → first_name/last_name split is checked too.
        $profile = self::profileFrom($request);

        $errors = static::collectErrors($request->all());
        $errors += PasswordPolicy::validate((string) $request->input('password', ''));
        $errors += Validator::make($profile, self::profileRules())->errors();
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return new self(
            username: Username::fromString(trim((string) $request->input('username', ''))),
            email: Email::fromString(trim((string) $request->input('email', ''))),
            password: (string) $request->input('password', ''),
            tenantId: (string) ($request->attribute('tenant') ?? ''),
            profile: $profile,
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

        // Some clients send a single "full name" instead of first/last. Split it
        // (first token → first_name, remainder → last_name) to fill only the
        // parts not already supplied explicitly — explicit fields always win.
        $full = trim((string) $request->input(
            'full_name',
            (string) $request->input(
                'fullname',
                (string) $request->input('name', '')
            )
        ));
        if ($full !== '') {
            $parts = preg_split('/\s+/', $full, 2) ?: [];
            $first = trim($parts[0] ?? '');
            $last = trim($parts[1] ?? '');
            if ($first !== '' && !isset($profile['first_name'])) {
                $profile['first_name'] = mb_substr($first, 0, self::PROFILE_FIELDS['first_name']);
            }
            if ($last !== '' && !isset($profile['last_name'])) {
                $profile['last_name'] = mb_substr($last, 0, self::PROFILE_FIELDS['last_name']);
            }
        }

        return $profile;
    }
}
