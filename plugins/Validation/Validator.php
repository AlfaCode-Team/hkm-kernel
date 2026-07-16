<?php

declare(strict_types=1);

namespace Plugins\Validation;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ValidationException;
use Plugins\I18n\Translator;

/**
 * Minimal, dependency-free validation engine.
 *
 * Produces the kernel's existing ValidationException (field => messages) so
 * controllers/DTOs get the framework's standard 422 error shape without each
 * one hand-rolling checks.
 *
 * Message resolution order per failed rule:
 *   1. custom override messages["{field}.{rule}"]
 *   2. Translator "validation.{rule}" (when a Translator is provided)
 *   3. built-in English default
 *
 * Usage:
 *   Validator::make($request->all(), [
 *       'email'    => 'required|email',
 *       'age'      => 'required|integer|min:18',
 *       'password' => 'required|string|min:8|confirmed',
 *   ])->validate();   // throws ValidationException on failure, returns validated data on success
 *
 * Supported rules:
 *   required, nullable, string, integer, numeric, boolean, array, email, url,
 *   http_url, timezone, min:n, max:n, between:a,b, in:a,b,c, regex:/.../,
 *   same:field, different:field, confirmed
 *
 * Custom rules — register once at bootstrap:
 *   Validator::extend('kebab',
 *       fn($v) => is_string($v) && preg_match('/^[a-z0-9-]+$/', $v) === 1,
 *       'The :field must be kebab-case.');
 *   // then: 'slug' => 'required|kebab'
 */
final class Validator
{
    /** @var array<string,list<string>> */
    private array $errors = [];

    /**
     * Custom rules registered via extend(). Process-wide (static) so a rule is
     * available to EVERY validator instance without re-registering.
     *
     * @var array<string, callable(mixed $value, ?string $param, array<string,mixed> $data): bool>
     */
    private static array $extensions = [];

    /** Default messages for custom rules, keyed by rule name. @var array<string,string> */
    private static array $extensionMessages = [];

    /**
     * Named rule GROUPS (CodeIgniter-style): a reusable {rules, messages} set
     * addressed by name via group(). Populated from config/validation.php.
     *
     * @var array<string, array{rules: array<string,string|list<string>>, messages: array<string,string>}>
     */
    private static array $groups = [];

    /** Built-in English defaults; :field and rule params are interpolated. */
    private const DEFAULTS = [
        'required'  => 'The :field field is required.',
        'string'    => 'The :field field must be a string.',
        'integer'   => 'The :field field must be an integer.',
        'numeric'   => 'The :field field must be numeric.',
        'boolean'   => 'The :field field must be true or false.',
        'array'     => 'The :field field must be an array.',
        'email'     => 'The :field field must be a valid email address.',
        'url'       => 'The :field field must be a valid URL.',
        'http_url'  => 'The :field field must be a valid http(s) URL.',
        'timezone'  => 'The :field field must be a valid timezone.',
        'enum'      => 'The selected :field is invalid.',
        'min'       => 'The :field field must be at least :min.',
        'max'       => 'The :field field must not be greater than :max.',
        'between'   => 'The :field field must be between :min and :max.',
        'in'        => 'The selected :field is invalid.',
        'regex'     => 'The :field field format is invalid.',
        'same'      => 'The :field field must match :other.',
        'different' => 'The :field field must be different from :other.',
        'confirmed' => 'The :field field confirmation does not match.',
    ];

    /**
     * @param array<string,mixed> $data
     * @param array<string,string|list<string>> $rules
     * @param array<string,string> $messages custom "field.rule" => message overrides
     */
    public function __construct(
        private readonly array $data,
        private readonly array $rules,
        private readonly array $messages = [],
        private readonly ?Translator $translator = null,
    ) {
    }

    /**
     * @param array<string,mixed> $data
     * @param array<string,string|list<string>> $rules
     * @param array<string,string> $messages
     */
    public static function make(array $data, array $rules, array $messages = [], ?Translator $translator = null): self
    {
        return new self($data, $rules, $messages, $translator);
    }

    /**
     * Register a CUSTOM rule.
     *
     * The callback receives the field value, the optional `:param` (e.g. the
     * `5` in `starts_with:5`), and the full input map (for cross-field rules).
     * Return true to pass, false to fail. Use it like any built-in rule:
     * `'slug' => 'required|kebab'`.
     *
     * Call this ONCE at bootstrap (a Provider::boot / project bootstrap) — the
     * registry is static and process-wide, so registering per-request is both
     * wasteful and unsafe under OpenSwoole. Registration is idempotent (same
     * name overwrites).
     *
     * @param callable(mixed $value, ?string $param, array<string,mixed> $data): bool $validator
     * @param string|null $message Default message; supports :field and :param.
     */
    public static function extend(string $rule, callable $validator, ?string $message = null): void
    {
        self::$extensions[$rule] = $validator;
        if ($message !== null) {
            self::$extensionMessages[$rule] = $message;
        }
    }

    /**
     * Register a RULE-SET class (CodeIgniter-style): every public method on the
     * class becomes a rule named after the method. Each method has the signature
     * `(mixed $value, ?string $param, array<string,mixed> $data): bool`. An
     * optional `messages(): array<string,string>` method supplies per-rule
     * default messages. Register once at bootstrap.
     *
     * @param object|class-string $ruleSet instance or class-string to construct
     */
    public static function extendWith(object|string $ruleSet): void
    {
        $instance = is_string($ruleSet) ? new $ruleSet() : $ruleSet;

        $messages = method_exists($instance, 'messages') ? $instance->messages() : [];

        foreach ((new \ReflectionClass($instance))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $name = $method->getName();
            // Skip framework hooks + magic methods — only rule methods register.
            if ($name === 'messages' || str_starts_with($name, '__')) {
                continue;
            }
            self::extend($name, $instance->{$name}(...), $messages[$name] ?? null);
        }
    }

    /**
     * Define a reusable named rule GROUP (CodeIgniter rule groups). Address it
     * later with group(). Typically populated from config/validation.php.
     *
     * @param array<string,string|list<string>> $rules
     * @param array<string,string> $messages
     */
    public static function defineGroup(string $name, array $rules, array $messages = []): void
    {
        self::$groups[$name] = ['rules' => $rules, 'messages' => $messages];
    }

    /**
     * Build a validator from a previously defined named group.
     *
     * @param array<string,mixed> $data
     */
    public static function group(string $name, array $data, ?Translator $translator = null): self
    {
        $group = self::$groups[$name]
            ?? throw new \InvalidArgumentException("Unknown validation group [{$name}].");

        return new self($data, $group['rules'], $group['messages'], $translator);
    }

    /** Drop all custom rules + groups — test helper; never needed on the hot path. */
    public static function flushExtensions(): void
    {
        self::$extensions = [];
        self::$extensionMessages = [];
        self::$groups = [];
    }

    public function fails(): bool
    {
        $this->run();
        return $this->errors !== [];
    }

    public function passes(): bool
    {
        return !$this->fails();
    }

    /** @return array<string,list<string>> */
    public function errors(): array
    {
        $this->run();
        return $this->errors;
    }

    /**
     * Validate and return only the validated fields; throws on failure.
     *
     * @return array<string,mixed>
     */
    public function validate(): array
    {
        if ($this->fails()) {
            throw new ValidationException($this->errors);
        }

        $validated = [];
        foreach (array_keys($this->rules) as $field) {
            if (array_key_exists($field, $this->data)) {
                $validated[$field] = $this->data[$field];
            }
        }
        return $validated;
    }

    private function run(): void
    {
        $this->errors = [];

        foreach ($this->rules as $field => $ruleSet) {
            $rules = is_array($ruleSet) ? $ruleSet : explode('|', $ruleSet);
            $value = $this->data[$field] ?? null;
            $present = array_key_exists($field, $this->data) && $value !== '' && $value !== null;

            // nullable short-circuits all other rules when the field is absent/empty.
            if (!$present && in_array('nullable', $rules, true)) {
                continue;
            }

            foreach ($rules as $rule) {
                if ($rule === 'nullable' || $rule === '') {
                    continue;
                }
                [$name, $param] = array_pad(explode(':', $rule, 2), 2, null);

                if ($name !== 'required' && !$present) {
                    continue; // value rules skip absent fields; required handles presence
                }

                $replace = $this->validateRule($name, $value, $param, $field);
                if ($replace !== null) {
                    $this->addError($field, $name, $replace);
                }
            }
        }
    }

    /**
     * Returns null when the rule passes, or an array of message replacements
     * (the rule failed).
     *
     * @return array<string,string|int|float>|null
     */
    private function validateRule(string $rule, mixed $value, ?string $param, string $field): ?array
    {
        $ok = match ($rule) {
            'required'  => $this->present($value),
            'string'    => is_string($value),
            'integer'   => filter_var($value, FILTER_VALIDATE_INT) !== false,
            'numeric'   => is_numeric($value),
            'boolean'   => in_array($value, [true, false, 0, 1, '0', '1', 'true', 'false'], true),
            'array'     => is_array($value),
            'email'     => filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            'url'       => filter_var($value, FILTER_VALIDATE_URL) !== false,
            'http_url'  => self::isHttpUrl($value),
            'timezone'  => is_string($value) && in_array($value, timezone_identifiers_list(), true),
            'enum'      => self::isEnumValue($value, $param),
            'min'       => $this->size($value) >= (float) $param,
            'max'       => $this->size($value) <= (float) $param,
            'between'   => $this->between($value, $param),
            'in'        => in_array((string) $value, explode(',', (string) $param), true),
            'regex'     => $param !== null && @preg_match($param, (string) $value) === 1,
            'same'      => $value === ($this->data[$param] ?? null),
            'different' => $value !== ($this->data[$param] ?? null),
            'confirmed' => $value === ($this->data[$field . '_confirmation'] ?? null),
            default     => $this->runExtension($rule, $value, $param),
        };

        return $ok ? null : $this->replacements($rule, $param);
    }

    /** @return array<string,string|int|float> */
    private function replacements(string $rule, ?string $param): array
    {
        $replace = [];
        switch ($rule) {
            case 'min':
            case 'max':
                $replace[$rule] = (string) $param;
                break;
            case 'between':
                [$a, $b] = array_pad(explode(',', (string) $param), 2, '');
                $replace['min'] = $a;
                $replace['max'] = $b;
                break;
            case 'same':
            case 'different':
                $replace['other'] = (string) $param;
                break;
            case 'in':
                $replace['values'] = (string) $param;
                break;
        }
        return $replace;
    }

    /** Dispatch a rule not known built-in to a registered custom rule (unknown → pass). */
    private function runExtension(string $rule, mixed $value, ?string $param): bool
    {
        $ext = self::$extensions[$rule] ?? null;

        // Unknown rule: pass rather than fail hard (a typo shouldn't 422 the world).
        return $ext === null ? true : $ext($value, $param, $this->data);
    }

    /** True when $value is a valid case of the (backed or pure) enum named in $param. */
    private static function isEnumValue(mixed $value, ?string $enum): bool
    {
        if ($enum === null || enum_exists($enum) === false) {
            return false;
        }
        if (method_exists($enum, 'tryFrom')) {                 // backed enum
            return (is_string($value) || is_int($value)) && $enum::tryFrom($value) !== null;
        }
        foreach ($enum::cases() as $case) {                    // pure enum → match name
            if ($case->name === $value) {
                return true;
            }
        }
        return false;
    }

    /** True for a syntactically valid absolute http/https URL. */
    private static function isHttpUrl(mixed $value): bool
    {
        if (!is_string($value) || filter_var($value, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));

        return $scheme === 'http' || $scheme === 'https';
    }

    private function present(mixed $value): bool
    {
        if (is_array($value)) {
            return $value !== [];
        }
        return $value !== null && $value !== '';
    }

    private function size(mixed $value): float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }
        if (is_array($value)) {
            return (float) count($value);
        }
        return (float) mb_strlen((string) $value);
    }

    private function between(mixed $value, ?string $param): bool
    {
        [$a, $b] = array_pad(explode(',', (string) $param), 2, null);
        $n = $this->size($value);
        return $n >= (float) $a && $n <= (float) $b;
    }

    /** @param array<string,string|int|float> $replace */
    private function addError(string $field, string $rule, array $replace): void
    {
        $replace['field'] = $field;
        $this->errors[$field][] = $this->message($field, $rule, $replace);
    }

    /** @param array<string,string|int|float> $replace */
    private function message(string $field, string $rule, array $replace): string
    {
        $custom = $this->messages["{$field}.{$rule}"] ?? null;
        if ($custom !== null) {
            return $this->interpolate($custom, $replace);
        }

        if ($this->translator !== null && $this->translator->has("validation.{$rule}")) {
            return $this->translator->get("validation.{$rule}", $replace);
        }

        $default = self::DEFAULTS[$rule]
            ?? self::$extensionMessages[$rule]
            ?? "The {$field} field is invalid.";

        return $this->interpolate($default, $replace);
    }

    /** @param array<string,string|int|float> $replace */
    private function interpolate(string $line, array $replace): string
    {
        foreach ($replace as $key => $value) {
            $line = str_replace(':' . $key, (string) $value, $line);
        }
        return $line;
    }
}
