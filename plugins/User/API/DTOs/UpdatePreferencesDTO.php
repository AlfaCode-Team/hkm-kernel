<?php

declare(strict_types=1);

namespace Plugins\User\API\DTOs;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use Plugins\User\Domain\ValueObjects\Theme;
use Plugins\Validation\AbstractDto;

/**
 * Validated preferences-update input (idempotent full replace). User id comes
 * from the Identity, never the body.
 */
final readonly class UpdatePreferencesDTO extends AbstractDto
{
    public function __construct(
        public ?string $language,
        public ?string $currency,
        public Theme $theme,
        public bool $reduceMotion,
        public bool $largerText,
        public bool $highContrast,
        public bool $screenReaderHints,
    ) {}

    protected static function rules(): array
    {
        return [
            'language' => 'nullable|regex:/^[a-zA-Z]{2,10}(-[a-zA-Z]{2,10})?$/',
            'currency' => 'nullable|regex:/^[a-zA-Z]{3}$/',
            'theme'    => 'nullable|enum:' . Theme::class,
        ];
    }

    protected static function messages(): array
    {
        return [
            'language.regex' => 'Language must be a 2–10 letter tag, e.g. en or en-GB.',
            'currency.regex' => 'Currency must be a 3-letter ISO 4217 code, e.g. UGX.',
            'theme.enum'     => 'Theme must be one of: light, dark, system.',
        ];
    }

    public static function fromRequest(Request $request): self
    {
        static::validated($request);

        return new self(
            language:          self::trimOrNull($request->input('language')),
            currency:          self::trimOrNull($request->input('currency')),
            theme:             Theme::fromString((string) $request->input('theme', 'system')),
            reduceMotion:      $request->boolean('reduceMotion'),
            largerText:        $request->boolean('largerText'),
            highContrast:      $request->boolean('highContrast'),
            screenReaderHints: $request->boolean('screenReaderHints'),
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
