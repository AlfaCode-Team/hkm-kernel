# Helpers — global functions and path utilities

Global helper functions for common tasks, registered via `composer.json` autoload. These are thin wrappers over kernel registries — stateless and safe under OpenSwoole.

## Path helpers

Registered via `Paths` singleton. Returns filesystem paths used by the application.

### base_path()

The workspace base directory (where `composer.json` and `src/` live):

```php
base_path();                    // '/app'
base_path('storage/logs');      // '/app/storage/logs'
```

### storage_path()

The persistent user data storage directory:

```php
storage_path();
storage_path('backups/2024-01');
```

Used for uploaded files, exports, backups — anything that must survive deploys.

### var_path()

Ephemeral runtime state (logs, caches, manifests, compiled routes). Per-project: lives under the active project root. Safe to wipe on deploy:

```php
var_path();                 // '/app/var' or '/app/projects/admin/var'
var_path('cache');          // '/app/var/cache'
var_path('logs/error.log');
```

### logs_path()

Application log streams:

```php
logs_path();
logs_path('error.log');
```

Equivalent to `var_path('logs/...')`.

### cache_path()

Compiled runtime caches (route manifests, config, etc.):

```php
cache_path();
cache_path('manifests/route-manifest.php');
```

Equivalent to `var_path('cache/...')`.

### userdata_path()

Per-tenant persisted state (uploads, reports, tenant-scoped data). Per-project, backed up and kept across deploys:

```php
userdata_path();
userdata_path('tenant-42/invoices');
```

Equivalent to `project_path('userdata/...')` in multi-project setups.

### config_path()

Project configuration files (database, app config, migrations):

```php
config_path();
config_path('database.php');
```

Equivalent to `project_path('config/...')`.

## Environment and configuration

### env()

Read environment values. Canonical accessor for the `.env` file.

```php
$debug = env('APP_DEBUG', false);
$key = env('APP_KEY');  // empty string if missing (use a default)
```

Reads from `$_ENV` and `$_SERVER` (where the loader injects values) and falls back to `getenv()` for OS variables. Never uses `putenv()`, which is unsafe under OpenSwoole.

Strict behavior: returns the raw string or `$default`; no boolean coercion. `env('APP_DEBUG')` returns the string `'false'` (truthy) from the .env file, not a boolean.

### config()

Read compiled configuration with dotted paths:

```php
$from = config('mail.from.address', 'noreply@example.test');
$mail = config('mail');  // whole group
$all  = config();        // entire config
```

Reads from `config-manifest.php`, compiled at boot from plugin and project `config/*.php` files (project deep-merged over plugins). Read-only by design — no setter. The manifest is cached via OPcache after the first read.

## Routing

### url()

The shared `UrlGenerator` instance, built from the compiled route-name index:

```php
url()->route('user.show', ['id' => 7]);
url()->signedRoute('email.verify', ['id' => 7], expiresIn: 3600);
```

Built once per process; subsequent calls return the cached instance.

### route()

Generate a URL for a named route:

```php
route('user.show', ['id' => 7]);                 // '/users/7'
route('user.show', ['id' => 7], absolute: true);  // 'https://app.test/users/7'
```

Throws on an unknown route name or a parameter value that violates its type constraint. Better than a broken link 404ing in production.

### signed_route()

A tamper-proof URL for one-time actions (email verification, password reset):

```php
$link = signed_route('email.verify', ['id' => $id], expiresIn: 3600);
// https://app.test/email/verify?id=...&signature=...&expires=...
```

The URL signature and expiration are verified by the kernel. Never guess at the signing logic; always use this helper.

## Collections

### collect()

Create a `Collection` from any iterable:

```php
$items  = collect([1, 2, 3])->map(fn($x) => $x * 2);
$emails = collect($users)->filter(fn($u) => $u['active'])->pluck('email')->toArray();
```

Returns a `Project\Support\Collection`. That class lives in the project layer (`projects/Support/Collection.php`), not in the kernel. Its methods: `all`, `count`, `isEmpty`, `isNotEmpty`, `map`, `filter`, `reject`, `each`, `reduce`, `first`, `last`, `pluck`, `keys`, `values`, `push`, `put`, `get`, `has`, `contains`, `merge`, `unique`, `reverse`, `slice`, `take`, `chunk`, `sort`, `sortBy`, `groupBy`, `where`, `sum`, `avg`, `min`, `max`, `implode`, `flatten`, `pipe`, `toArray`, `toJson`. It is also `ArrayAccess`, `IteratorAggregate`, `Countable` and `JsonSerializable`.

::: warning
Do not use `collect()` inside the HTTP layer or a Domain class: it is a global and pulls in a project-layer type. It is a convenience for application and CLI code.
:::

## Plugin detection

### plugin_installed()

Is a plugin/module installed in THIS application? Checks the compiled service manifest.

```php
if (plugin_installed('tenancy.routing')) {
    // the plugin was registered in withModules()
}

if (plugin_installed('auth.identity', 'user.management')) {
    // all of them are installed
}
```

Accepts either the module's `solves` domain or its `name` from `module.json`. With no arguments, returns false (safety default).

Use this for OPTIONAL integrations. Prefer declaring a hard `requires[]` dependency in your own `module.json` — that fails the boot with a clear message if a required plugin is missing.

### installed_plugins()

Every installed module, as a `solves` domain => `name` map:

```php
$all = installed_plugins();  // ['tenancy.routing' => 'tenancy', ...]
```

The synthetic `__project__` scope is excluded.

## Paths class

Direct access to the registry. Rarely needed in application code, but useful in bootstrap files:

```php
use AlfacodeTeam\PhpServicePlatform\Kernel\Support\Paths;

Paths::setBase('/srv/app');
Paths::setProject('/srv/app/projects/admin');

echo Paths::base('config');      // '/srv/app/config'
echo Paths::project('var');      // '/srv/app/projects/admin/var'
echo Paths::logs('app.log');     // '/srv/app/projects/admin/var/logs/app.log'
```

- `setBase(string $path)` — set the workspace base directory (call once, before boot).
- `setProject(?string $path)` — set the active project root. When set, `var/` and `userdata/` resolve under the project instead of the workspace base.
- `base(string $append)`, `project()`, `storage()`, `var()`, `logs()`, `cache()`, `userdata()`, `config()` — retrieve paths with optional subpath.

## Plugins class

Plugin detection via the compiled service manifest. Direct access:

```php
use AlfacodeTeam\PhpServicePlatform\Kernel\Support\Plugins;

Plugins::all();               // all installed, domain => name
Plugins::installed('tenancy.routing');  // true if registered
Plugins::missing('auth.identity');      // true if NOT installed
```

Methods:

- `all()` — every installed module as `solves` domain => `name` from module.json.
- `installed(string $domainOrName)` — true if installed. Accepts either the domain or the module name.
- `missing(string ...$domainsOrNames)` — which of these are NOT installed, as an array.

Use `missing()` when you need to check several at once and only fail if ALL are absent.

## Config and Repository

### Configuration::Repository

Compiled configuration accessed via `config()` is a `Repository` instance. Direct access:

```php
use AlfacodeTeam\PhpServicePlatform\Kernel\Config\Repository;

$repo = config();  // returns the shared Repository
echo $repo->get('mail.from.address');
echo $repo->all();  // entire configuration
```

- `get(string $key, mixed $default)` — dotted path access.
- `all()` — entire configuration as a nested array.

The repository is immutable and read-only; configuration is decided at boot, not mutated at runtime.

## Best practices

::: tip

**Use helpers in bootstrap and templates.** These functions exist so files outside the DI container (bootstrap files, templates, standalone CLI scripts) can access kernel services.

**Inject services into classes.** Inside a service or controller, inject `LoggerPort`, `UrlGenerator`, or `CachePort` rather than using globals. It makes code testable and explicit about dependencies.

**Path helpers are safer than hardcoding.** Using `logs_path()` survives a deployment structure change. Hardcoded `/app/var/logs` does not.

:::

## Common mistakes

::: warning

**Using getenv() instead of env().** `getenv()` is unsafe under OpenSwoole (it's a global) and doesn't read the loader's `$_ENV`. Use `env()`.

**Using $_ENV or $_SERVER directly instead of env().** A prefix before the `= ` sign is silently ignored when you read `$_ENV` directly; use `env()` to apply the right precedence.

**Mutating config() results.** Configuration is read-only. You cannot call `config()->set('key', value)` — no setter exists. Change configuration at boot via `withConfig()` or a config file.

**Storing plugin checks in statics.** `plugin_installed()` reads the compiled manifest once per process and caches it, so it's cheap to call repeatedly. Caching the result in a static adds no benefit and couples the code to a global state.

:::

## Source

- [Global helpers](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Support/helpers.php)
- [Paths](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Support/Paths.php)
- [Plugins](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Support/Plugins.php)
- [Repository](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Config/Repository.php)
