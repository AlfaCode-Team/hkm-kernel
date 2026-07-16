<?php

declare(strict_types=1);

namespace Plugins\Validation;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use Plugins\I18n\Translator;

/**
 * Base class for request DTOs that validate their input.
 *
 * A concrete DTO declares its {@see rules()} (and optional {@see messages()}),
 * then in its own fromRequest() calls {@see validated()} to get a checked field
 * map — no more hand-rolled `$errors[]` accumulation. Shape validation
 * (required / type / length / format) lives in the rules; deep DOMAIN
 * invariants still belong in the value objects the DTO constructs.
 *
 * `readonly` so a `final readonly class X extends AbstractDto` is legal (PHP only
 * lets a readonly class extend another readonly class). The base holds no state.
 *
 * Example:
 *   final readonly class UpdateProfileDTO extends AbstractDto
 *   {
 *       public function __construct(public ?string $firstName) {}
 *
 *       protected static function rules(): array
 *       {
 *           return ['firstName' => 'nullable|string|max:80'];
 *       }
 *
 *       public static function fromRequest(Request $request): self
 *       {
 *           $v = static::validated($request);           // throws 422 on bad input
 *           return new self(firstName: $v['firstName'] ?? null);
 *       }
 *   }
 */
abstract readonly class AbstractDto
{
    /**
     * Field => rule(s), e.g. ['email' => 'required|email|max:150'].
     *
     * @return array<string, string|list<string>>
     */
    abstract protected static function rules(): array;

    /**
     * Optional custom "field.rule" => message overrides.
     *
     * @return array<string, string>
     */
    protected static function messages(): array
    {
        return [];
    }

    /**
     * Validate the request (body + query merged) against rules(). Throws
     * ValidationException (kernel 422) on failure; returns the validated map.
     *
     * @return array<string, mixed>
     */
    final protected static function validated(Request $request, ?Translator $translator = null): array
    {
        return static::validate($request->all(), $translator);
    }

    /**
     * Validate a raw input array — for callers that already have the map (jobs,
     * CLI, sub-DTOs) and no Request.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    final protected static function validate(array $input, ?Translator $translator = null): array
    {
        return Validator::make($input, static::rules(), static::messages(), $translator)->validate();
    }

    /**
     * Validate and RETURN the error map WITHOUT throwing — so a DTO can merge in
     * domain-level errors (value-object / policy failures) and raise a single
     * combined ValidationException. Empty array = shape is valid.
     *
     * @param array<string, mixed> $input
     * @return array<string, string|list<string>>
     */
    final protected static function collectErrors(array $input, ?Translator $translator = null): array
    {
        return Validator::make($input, static::rules(), static::messages(), $translator)->errors();
    }
}
