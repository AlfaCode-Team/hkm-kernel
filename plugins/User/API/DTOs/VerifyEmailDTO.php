<?php

declare(strict_types=1);

namespace Plugins\User\API\DTOs;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use Plugins\Validation\AbstractDto;

/**
 * Email-verification input: a signed/opaque token the user received by email.
 * The token is verified out-of-band by the service (timing-safe compare).
 */
final readonly class VerifyEmailDTO extends AbstractDto
{
    public function __construct(
        public string $token,
    ) {}

    protected static function rules(): array
    {
        return ['token' => 'required|string'];
    }

    protected static function messages(): array
    {
        return ['token.required' => 'A verification token is required.'];
    }

    public static function fromRequest(Request $request): self
    {
        static::validated($request);

        return new self(token: trim((string) $request->input('token', '')));
    }
}
