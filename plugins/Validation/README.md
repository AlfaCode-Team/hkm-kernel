# Validation plugin

A shared, dependency-free request-validation engine. It produces the kernel's
standard `ValidationException` (a 422 with `{ field: [messages] }`), so DTOs stop
hand-rolling `$errors[]` accumulation.

- `Validator` — the rule engine (a plain autoloaded class; validation is DI-free
  boundary logic, so it needs no container).
- `AbstractDto` — base class for request DTOs: declare `rules()`, call
  `validated($request)`.
- `config/validation.php` — CodeIgniter's `Config\Validation` equivalent
  (rule-sets + rule-groups), loaded once at boot by `Provider`.

## 1. Validating a DTO

```php
use Plugins\Validation\AbstractDto;

final readonly class UpdateProfileDTO extends AbstractDto
{
    public function __construct(public ?string $firstName, public ?string $avatarUrl) {}

    protected static function rules(): array
    {
        return [
            'firstName' => 'nullable|string|max:80',
            'avatarUrl' => 'nullable|http_url|max:500',
        ];
    }

    protected static function messages(): array   // optional per-rule overrides
    {
        return ['avatarUrl.http_url' => 'Avatar must be an http(s) URL.'];
    }

    public static function fromRequest(Request $request): self
    {
        static::validated($request);   // throws 422 on bad shape
        return new self($request->input('firstName'), $request->input('avatarUrl'));
    }
}
```

**Division of labour:** rules validate *shape* (required / type / length /
format); deep *domain* invariants stay in the value objects the DTO constructs.

## 2. Built-in rules

```
required nullable string integer numeric boolean array
email url http_url timezone
min:n max:n between:a,b in:a,b,c regex:/.../
same:field different:field confirmed enum:Class
```

## 3. Adding rules — three ways (all register ONCE at boot)

### a. A single closure

```php
Validator::extend('even',
    fn($value) => (int) $value % 2 === 0,
    'The :field must be even.');
// use it: 'quantity' => 'required|even'
```

### b. A rule-set CLASS (CodeIgniter style)

Every public method becomes a rule; `messages()` supplies defaults.

```php
final class CommonRules
{
    public function slug(mixed $v, ?string $p, array $data): bool
    {
        return is_string($v) && preg_match('/^[a-z0-9-]+$/', $v) === 1;
    }
    public function messages(): array
    {
        return ['slug' => 'The :field must be kebab-case.'];
    }
}

Validator::extendWith(CommonRules::class);   // registers slug + any other methods
```

Shipped rule-set packs (both enabled by default via `config/validation.php`),
mirroring how CodeIgniter splits FormatRules / Rules / CreditCardRules:

**`CommonRules`** — universal rules on top of the built-ins:

```text
alpha alpha_num alpha_dash alpha_space alpha_numeric_punct ascii lowercase uppercase
digits[:n] digits_between:a,b is_natural is_natural_no_zero hex decimal[:p|:a,b]
multiple_of:n min_digits:n max_digits:n size:n gt:n gte:n lt:n lte:n
starts_with:… ends_with:… doesnt_start_with:… doesnt_end_with:… not_in:…
distinct list
uuid ulid slug username
ip ipv4 ipv6 mac_address domain
json base64 hex_color
date date_format:Y-m-d before:… after:…
accepted declined locale currency phone e164
```

**`FinancialRules`** — money / payment fields:

```text
luhn credit_card cvv iban bic
```

> Cross-field presence rules (`required_if` / `required_with`) are intentionally
> not provided — the engine skips value rules on absent fields, so they can't be
> expressed as extensions. Enforce those in the service layer.

Rule method signature: `(mixed $value, ?string $param, array $data): bool`.
`$param` is the `:arg` in `rule:arg`; `$data` is the full input (cross-field rules).

### c. Named rule GROUPS (CodeIgniter rule groups)

Reusable `{rules, messages}` sets addressed by name:

```php
Validator::defineGroup('login', [
    'email'    => 'required|email',
    'password' => 'required|string|min:8',
], ['email.required' => 'We need your email.']);

Validator::group('login', $request->all())->validate();
```

## 4. Configuration — `config/validation.php`

The CI `Config\Validation` equivalent. The `Provider` reads it at boot and wires
it in — no core edits, no per-request cost. A project may override it by placing
its own `config/validation.php` (resolved via `config_path()`).

```php
return [
    'rulesets' => [ CommonRules::class ],        // CI $ruleSets → extendWith()
    'groups'   => [                               // CI rule groups → defineGroup()
        'login' => ['rules' => [...], 'messages' => [...]],
    ],
];
```

## Message resolution order (per failed rule)

1. DTO/`make()` override — `messages["{field}.{rule}"]`
2. I18n translator — `validation.{rule}` (when a `Translator` is passed)
3. built-in default, then a registered custom-rule default

## Notes

- `extend` / `extendWith` / `defineGroup` use a **process-wide static** registry:
  register at bootstrap (a Provider `boot()` / project bootstrap), never
  per-request — safe and cheap under OpenSwoole. `flushExtensions()` is a test
  helper only.
- Unknown rules **pass** rather than fail hard, so a rule typo never 422s a whole
  request surface by accident.
