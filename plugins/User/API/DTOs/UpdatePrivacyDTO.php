<?php

declare(strict_types=1);

namespace Plugins\User\API\DTOs;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use Plugins\User\Domain\ValueObjects\ProfileVisibility;
use Plugins\Validation\AbstractDto;

/**
 * Validated privacy-update input (idempotent full replace). User id comes from
 * the Identity, never the body.
 */
final readonly class UpdatePrivacyDTO extends AbstractDto
{
    public function __construct(
        public ProfileVisibility $profileVisibility,
        public bool $showPhone,
        public bool $showEmail,
        public bool $marketingOptIn,
        public bool $analyticsOptIn,
    ) {}

    protected static function rules(): array
    {
        return ['profileVisibility' => 'nullable|enum:' . ProfileVisibility::class];
    }

    protected static function messages(): array
    {
        return ['profileVisibility.enum' => 'Profile visibility must be one of: public, private, contacts.'];
    }

    public static function fromRequest(Request $request): self
    {
        static::validated($request);

        return new self(
            profileVisibility: ProfileVisibility::fromString((string) $request->input('profileVisibility', 'public')),
            showPhone:         $request->boolean('showPhone'),
            showEmail:         $request->boolean('showEmail'),
            marketingOptIn:    $request->boolean('marketingOptIn'),
            analyticsOptIn:    $request->boolean('analyticsOptIn'),
        );
    }
}
