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
 * Partial-update input. Every field is optional: only the keys PRESENT and
 * non-empty are applied (PATCH semantics), so a caller can change just the email
 * without resending the username or password. The rules carry no `required`, so
 * the engine skips any absent/empty field.
 *
 * The plaintext password (when present) is held only long enough for the
 * service to hash it via the HashingPort, then discarded.
 */
final readonly class UpdateUserDTO extends AbstractDto
{
    public function __construct(
        public ?Username $username,
        public ?Email $email,
        public ?string $password,
    ) {}

    protected static function rules(): array
    {
        return [
            'username' => 'string|min:5|max:50|regex:/^[A-Za-z0-9._-]+$/',
            'email'    => 'string|email|max:150',
            'password' => 'string',
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
        $errors = static::collectErrors($request->all());
        if ($request->filled('password')) {
            $errors += PasswordPolicy::validate((string) $request->input('password', ''));
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $username = $request->filled('username')
            ? Username::fromString(trim((string) $request->input('username', '')))
            : null;
        $email = $request->filled('email')
            ? Email::fromString(trim((string) $request->input('email', '')))
            : null;
        $password = $request->filled('password') ? (string) $request->input('password', '') : null;

        if ($username === null && $email === null && $password === null) {
            throw new ValidationException(['_' => 'Provide at least one of: username, email, password.']);
        }

        return new self(username: $username, email: $email, password: $password);
    }

    public function hasChanges(): bool
    {
        return $this->username !== null || $this->email !== null || $this->password !== null;
    }
}
