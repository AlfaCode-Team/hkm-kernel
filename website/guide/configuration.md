# Configuration

HKM loads configuration through a multi-stage process: environment variables from `.env`, plus config files in `config/`. The kernel compiles these at boot into an immutable, dotted-key repository. This guide covers how configuration works and the rules that make it safe and predictable.

## Environment variables (.env)

Configuration values come from the process environment (OS/server variables) plus `.env` files loaded by the project bootstrap.

The loader is `Project\Bootstrap\Environment\LoadEnvironment`, part of the project layer rather than the kernel: the kernel itself never reads a `.env` file. Generated entry points call it before the kernel builder runs:

```php
LoadEnvironment::load($projectRoot, $domain, $_SERVER['argv'] ?? null);
```

It ships its own small parser (no `vlucas/phpdotenv` dependency), so it also works from a native install with no `vendor/` directory.

### The .env cascade

Every file is optional, and a later file overrides keys set by an earlier one:

| Tier | Files (in load order) |
|---|---|
| 1. Base | `{root}/.env`, then `{root}/.env.{APP_ENV}` |
| 2. Domain | `{root}/.env.{sld}`, `{root}/.env.{sub}`, `{root}/.env.{sub}.{sld}` |
| 3. Project | `{projectPath}/.env`, then the same domain files under `{projectPath}` |

- `APP_ENV` comes from the `--env` CLI option, the real environment, or `.env` itself: `.env` is read first, so it may set `APP_ENV` and the matching `.env.{APP_ENV}` is still picked up. With `APP_ENV=local`, that file is `.env.local`.
- `sld` and `sub` come from the request host (or `--domain` / `APP_DOMAIN` on the CLI): for `admin.shop.example.com`, `sld` is `example` and `sub` is `admin.shop`. A host with fewer than two labels has no domain tier.
- Tier 3 only loads when the host resolved to a project whose path is **inside** the application root. A host registered to a project elsewhere on the machine is refused and logged, so a request cannot splice another application's `APP_KEY` or `DB_*` values into yours by choosing its `Host` header.
- Values may reference earlier ones: `${VAR}` and `$VAR` are expanded from what is already loaded.

Commit a `.env.example` with placeholders for onboarding, but note that the loader never reads it.

### Reading environment variables

**Always use the `env()` helper, never `getenv()`:**

```php
// ✓ Good
$apiKey = env('STRIPE_KEY');
$port = (int) env('SERVER_PORT', '8000');

// ✗ Bad — getenv() is unreliable
$apiKey = getenv('STRIPE_KEY');
```

**Why?** The bootstrap loads `.env` values into `$_ENV` and `$_SERVER`, not via `putenv()`. This avoids the expensive, coroutine-unsafe `putenv()` call. The `env()` helper reads from `$_ENV`/`$_SERVER` first, then falls back to `getenv()` for genuine OS variables.

```php
function env(string $key, mixed $default = null): mixed
{
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    return ($value === false || $value === null) ? $default : $value;
}
```

### Precedence

A value that is already in the real process environment, visible to `getenv()` but not yet in `$_ENV` or `$_SERVER`, is never overwritten by a `.env` file. That lets a server or container inject configuration without editing files.

::: warning A shell prefix does not override .env on the CLI
The PHP CLI copies the environment into `$_SERVER` before any code runs, so the loader treats it like a file value and the cascade overwrites it. `DB_HOST=other hkm cli …` therefore loses to `DB_HOST` in `.env`, silently. For a local override, put it in `.env.{APP_ENV}` (for example `.env.local`) instead.
:::

### Compiled env cache (ENV_CACHE)

Under PHP-FPM or `php -S`, every request re-parses the cascade. Set `ENV_CACHE=1` in the real environment (or call `LoadEnvironment::useCache(true)`) and the resolved values are written to `var/cache/env.<host>.<env>.php`, which opcache keeps in memory. The cache records the mtime and size of every candidate file, including absent ones, and is rebuilt automatically when any of them changes. Leave it off in development: mtime has one-second granularity. Under OpenSwoole it brings nothing, because the env is loaded once per worker anyway.

### putenv() is off by default

Values go into `$_ENV` and `$_SERVER` only. `putenv()` is coroutine-unsafe under OpenSwoole and dominates the injection cost, so it is opt-in via `LoadEnvironment::useProcessEnv(true)`. Use that only for a third-party SDK that reads the OS environment directly.

## Configuration files (config/*.php)

Beyond environment variables, the project can declare structured configuration in `config/*.php` files:

```php
// config/mail.php
return [
    'driver' => env('MAIL_DRIVER', 'smtp'),
    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', 'noreply@example.test'),
        'name'    => env('MAIL_FROM_NAME', 'Example'),
    ],
];
```

These files are loaded at boot, deep-merged (project over plugin), and compiled into `var/cache/manifests/config-manifest.php`.

### Reading compiled configuration

Use the `config()` helper with dotted paths:

```php
// Get a single value
$driver = config('mail.driver');

// Get with a default
$timeout = config('database.timeout', 30);

// Get a whole group
$mailConfig = config('mail');        // returns the entire array

// Check if a key exists
if (config()->has('mail.from')) {
    // ...
}
```

The `config()` helper returns an immutable `Config\Repository` instance:

```php
class Repository
{
    public function get(string $key, mixed $default = null): mixed;
    public function has(string $key): bool;
    public function group(string $name): array;
    public function all(): array;  // every compiled key
}
```

**Configuration is read-only by design.** There is no `config('key', value)` setter. Configuration is decided at boot, and a mutable global would be shared across coroutines under OpenSwoole. If a value must change per request, it is request state, not configuration—put it on the Request or in the ModuleContainer.

### Project-over-plugin merging

The kernel compiles `config/*.php` with the project deep-merged over plugins:

```
config-manifest.php is built from:
  1. Every plugin's config/ files (combined)
  2. The project's config/ files (merged over step 1, key by key)
```

This lets the project override specific settings without forking a plugin. A plugin defines the defaults; the project can change only what it names.

```php
// plugin/config/auth.php
return [
    'driver'  => 'session',
    'tokens'  => ['cookie' => 'auth_token'],
    'guard'   => 'web',
];

// project/config/auth.php (project overrides only what matters)
return [
    'driver' => env('AUTH_DRIVER', 'jwt'),  // Override driver, keep the rest
];

// Compiled result: driver=jwt, tokens=[...], guard=web
```

## Module.json configuration declarations

Modules declare which environment variables they need in `module.json` under `config[]`. This is the **single source of truth** for what variables a module requires.

### Three declaration forms

Configuration can be declared as a bare string, or as an object with validation:

```json
{
  "config": [
    "STRIPE_API_KEY",
    { "key": "STRIPE_WEBHOOK_SECRET", "type": "string", "required": true },
    { "key": "INVOICE_TAX_RATE", "type": "float", "required": false, "default": 0.18 },
    { "key": "ENABLE_CACHE", "type": "bool", "required": false, "default": true }
  ]
}
```

| Form | Meaning | Written to .env |
|---|---|---|
| Bare string | Required, no default | `STRIPE_API_KEY=` (empty value, fails boot) |
| `required: true` (default) | Must be present in env | `VAR=` (empty, fails boot) |
| `required: false, no default` | Optional, no preset | `# VAR=` (commented out) |
| `required: false, has default` | Optional with default | `VAR=default_value` (active) |

When a plugin is enabled with `hkm plugins enable`, its `config[]` is seeded into the project's `.env` file, under a labeled block:

```bash
# ── Invoice module ─────────────────────────────────────
INVOICE_CURRENCY=USD
# INVOICE_TAX_RATE=0.18
INVOICE_API_KEY=
```

### Type validation

Supported types (validated at boot):

| Type | Accepts |
|---|---|
| `string` (default) | Any string value |
| `int` or `integer` | Digits only (negative allowed, e.g., `-5`) |
| `float` | Numeric values (e.g., `3.14`, `2`, `.5`) |
| `bool` or `boolean` | `true`, `false`, `1`, `0`, `yes`, `no` (case-insensitive) |

### ValidateConfigStage

At boot, `ValidateConfigStage` checks every declared config:

1. If a value is empty (`''` or `null`) AND `required: true`, the boot **fails with a descriptive message**.
2. If a value violates its `type` constraint, the boot **fails**.
3. If a value is empty AND `required: false`, it is skipped (the module can handle the absence).

All problems are reported in one exception, not just the first:

```
Configuration invalid.
  Missing: STRIPE_API_KEY (required by invoice), API_SECRET (required by payments)
  Invalid: INVOICE_TAX_RATE must be float (declared by invoice)
```

## Built-in environment variables

The kernel looks for certain variables to configure itself:

| Variable | Purpose | Example |
|---|---|---|
| `APP_ENV` | Environment tier (local, staging, production, testing) | `local` |
| `APP_KEY` | Base64-encoded encryption key (32 bytes) | `base64:...` |
| `APP_URL` | Base URL for absolute link generation | `https://example.test` |
| `APP_TIMEZONE` | Timezone for date/time (PHP date_default_timezone_set) | `UTC` |
| `LOG_CHANNEL` | Default log channel | `single`, `stack`, `stdout` |
| `LOG_LEVEL` | Minimum log level | `debug`, `info`, `warning`, `error` |
| `BOOT_CACHE` | Skip recompiling unchanged manifests (FPM only) | `1` or `0` |
| `HKM_PROJECT` | Fallback project name if Host header doesn't resolve | `admin` |
| `REDIS_HOST` | Redis host (enables Redis cache/queue) | `127.0.0.1` |
| `REDIS_PORT` | Redis port | `6379` |

See your plugin documentation for plugin-specific variables (DATABASE_URL, MAIL_DRIVER, etc.).

## Configuration examples

### Enable Redis for cache and queue

Add to `.env`:

```bash
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
```

The RedisCache plugin (enabled by default in the bootstrap) reads these and overrides the default in-memory cache.

### Override a plugin's behavior

Plugin defines a default; project overrides it:

```php
// plugin/config/auth.php
return ['driver' => 'session'];

// project/config/auth.php
return ['driver' => 'jwt'];
```

At boot, the project's value wins.

### Environment-specific config

The kernel has no per-environment overlay for `config/*.php`. Keep those files the same in every environment and let the values vary through `env()`, set per tier in `.env.{APP_ENV}`:

```php
// config/cache.php
return [
    'default' => env('CACHE_DRIVER', 'array'),
    'ttl'     => (int) env('CACHE_TTL', 0),
];
```

```ini
# .env.production
CACHE_DRIVER=redis
CACHE_TTL=3600
```

A scaffolded project does contain `config/environments/{local,staging,production,testing}.php`, but these are **migration-engine connection configs**, not app config, and nothing loads them automatically. Select one explicitly with `--config=<path>` on the [migrate commands](/cli/built-in-commands).

## Secrets and sensitive data

### Never commit secrets

`.env` and `.env.local` are gitignored. Commit only `.env.example` with placeholders:

```bash
# .env.example (committed)
STRIPE_API_KEY=sk_test_...
DATABASE_PASSWORD=  # Fill in your local password here
```

### Generating APP_KEY

The kernel uses `APP_KEY` as the default HMAC secret for [CSRF tokens](/security/csrf) and [signed URLs](/routing/named-routes). With it empty, `CsrfTokenLayer` fails closed (denies unsafe requests) and signing a URL throws. The generated `app/bootstrap/app.php` goes further: unless `APP_ENV` is `local` or `testing`, it **refuses to boot** without an `APP_KEY`. To rotate the key, move the old value to `APP_KEY_PREVIOUS` so data encrypted with it stays readable.

`hkm install` creates `.env` from `.env.example` when it is missing, and fills an empty `APP_KEY=` line with 32 random bytes, base64-encoded (`--no-key` skips this). To generate one by hand:

```bash
php -r "echo base64_encode(random_bytes(32)).PHP_EOL;"
```

```ini
APP_KEY=your_generated_key_here
```

The value is used as-is, so there is no `base64:` prefix to add. Changing it invalidates every outstanding CSRF token and signed link.

### .env file permissions

The `.env` file contains secrets and should never be world-readable:

```bash
chmod 600 .env
```

The `hkm install --production` command sets correct permissions automatically.

## Common mistakes

::: warning `.env` changes require a restart

Under PHP-FPM, the bootstrap re-executes on every request. Changes to `.env` are picked up immediately.

Under OpenSwoole, the bootstrap runs once per worker. **Restart workers to pick up `.env` changes:**

```bash
hkm worker stop && hkm worker start
```

:::

::: warning Never use `putenv()` in first-party code

`putenv()` is globally mutable and unsafe under OpenSwoole coroutines. The bootstrap avoids it entirely. If you are tempted to write:

```php
// ✗ Bad
putenv('NEW_VAR=value');
```

Instead, pass the value through the DI container or the request.

:::

::: warning `env()` returns strings

The `env()` helper always returns a string (or the default). Type coercion is up to you:

```php
// ✗ Bad — env() returns a string "0" or "1", both truthy
if (env('FEATURE_ENABLED')) {  // Always true!
    // ...
}

// ✓ Good
if (filter_var(env('FEATURE_ENABLED'), FILTER_VALIDATE_BOOL)) {
    // ...
}
```

For typed config, use `config()` on a PHP file that coerces the type:

```php
// config/features.php
return [
    'cache_enabled' => filter_var(env('FEATURE_CACHE'), FILTER_VALIDATE_BOOL, false),
];
```

:::

## Source

- [src/Kernel/Support/helpers.php](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Support/helpers.php) — env(), config() helpers
- [src/Kernel/Config/Repository.php](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Config/Repository.php) — Configuration reader
- [src/Kernel/Boot/Stages/ValidateConfigStage.php](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Boot/Stages/ValidateConfigStage.php) — Config validation
- [src/Kernel/Boot/Stages/CompileConfigManifestStage.php](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Boot/Stages/CompileConfigManifestStage.php) — Config merging and compilation
