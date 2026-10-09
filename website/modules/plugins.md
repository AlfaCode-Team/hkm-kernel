# Plugins: Loading and Activation

A plugin is a module installed in your project as a first-party package under `plugins/` and autoloaded via the `Plugins\` namespace. Every plugin is a full module with its own `module.json` and `Provider.php`. The kernel loads and validates plugins at boot, enforcing no dependencies on the kernel itself — a correctly written plugin works with zero kernel changes.

## Finding and Loading Plugins

### Directory Layout

Plugins live in a `plugins/` directory in your project root. The directory structure mirrors any namespace:

```
plugins/
├── Example/
│   ├── module.json
│   ├── Provider.php
│   ├── config/
│   ├── resources/
│   └── src/
│       ├── API/
│       │   └── Contracts/
│       ├── Application/
│       │   └── Services/
│       ├── Domain/
│       ├── Infrastructure/
│       └── Http/
│           └── Controllers/
├── Auth/
│   ├── module.json
│   ├── Provider.php
│   └── ...
└── Payment/
    ├── module.json
    ├── Provider.php
    └── ...
```

### Namespace Mapping

Your project's `composer.json` must declare the `Plugins\` namespace as a PSR-4 path repository pointing to `plugins/`:

```json
{
  "autoload": {
    "psr-4": {
      "Plugins\\": "plugins/"
    }
  }
}
```

The kernel discovers plugins by scanning this directory. When you declare a plugin in `withModules([...])`:

```php
Kernel::configure()
    ->withModules([
        Plugins\Example\Provider::class,
        Plugins\Auth\Provider::class,
    ])
    ->build();
```

The kernel loads each `Provider.php` and reads its `module.json` from the same directory.

::: info
The `Plugins\` namespace is a convention, not a requirement. Any PSR-4 namespace in `composer.json` works — but `Plugins\` is the standard across HKM projects.
:::

## The Plugins Helper Class

The `Plugins` class queries which modules are installed. It reads the compiled `service-manifest.php`, which contains the `solves` domains of every module passed to `withModules([...])`.

```php
use AlfacodeTeam\PhpServicePlatform\Kernel\Support\Plugins;

// Every installed module, keyed by solves domain
Plugins::all();                    // ['invoice.generation' => 'invoice', ...]

// Is a plugin installed? Accept domain or name
Plugins::installed('invoice.generation');   // true (by domain)
Plugins::installed('invoice');              // true (by name)
Plugins::installed('missing.plugin');       // false

// Which of these are missing?
Plugins::missing('invoice.generation', 'missing.plugin');  // ['missing.plugin']

// Assert all are installed, or throw with what IS registered
Plugins::ensure('invoice.generation', 'auth.identity');   // throws if missing
```

::: tip
`Plugins::all()` returns only real modules, not the synthetic `__project__` scope (project-layer routes have no owning module).
:::

### The `plugin_installed()` Helper

A global helper wraps `Plugins::missing()` for call sites that read better in prose:

```php
if (plugin_installed('audit.trail', 'email.delivery')) {
    // Both plugins are installed
    $this->enableAdvancedFeatures();
} else {
    // Gracefully degrade
    $this->enableBasicFeatures();
}
```

Accepts both domain and name (short or fully qualified). With no arguments, returns false (every plugin in an empty list is installed is never what a caller meant).

::: warning
Use `Plugins::ensure()` or throw `KernelException` to fail loudly when a plugin is required. `plugin_installed()` is for optional integrations — light up a feature when the plugin is there, degrade quietly when it is not.
:::

## Module Files: Loading Global Functions

Plugins often define global helper functions that no class autoloader can reach. The `files` field in `module.json` declares these.

```json
{
  "files": ["src/Support/helpers.php", "src/Support/macros.php"]
}
```

**Boot flow:**

1. **`CompileModuleFilesStage`** (compile) — Reads each module's `module.json` `files[]`.
   - Paths are relative to the module directory.
   - A declared file that does not exist **fails the boot** (it is a kernel contract).
   - When `files` is omitted, the kernel falls back to the module's `composer.json` `"autoload.files"`.
   - Output: `files-manifest.php` — an ordered list of absolute paths.

2. **`LoadModuleFilesStage`** (always runs, even with cached boot) — Requires each file via `require_once`.
   - Runs on **every build**, not just the first one, because global functions live in the PHP process, not in a manifest.
   - Missing files (from a plugin removed between deploys) are skipped, making that a recoverable state.

**Correct declaration:**

```json
{
  "files": ["src/helpers/invoice.php"]
}
```

**Wrong — will fail the boot:**

```json
{
  "files": ["src/helpers/missing-file.php"]
}
```

::: details When to use module.json `files` vs `composer.json` autoload.files

Use `module.json` `files` when:
- You are writing a NEW plugin and want to declare everything in one place.
- You want the kernel to fail the boot if a file is missing (strict validation).

Use `composer.json` `"autoload.files"` when:
- The plugin already has a `composer.json` (e.g., it was extracted from another framework).
- The kernel falls back to it automatically when `files` is omitted (best-effort).
:::

## Plugin Routes

Plugins declare routes in their `module.json` `routes[]` field, exactly like projects do in `proj.json`. The compilation and resolution are identical.

```json
{
  "routes": [
    {
      "method": "GET",
      "path": "/invoices",
      "handler": "Plugins\\Invoice\\Http\\InvoiceController@index",
      "name": "invoice.index"
    }
  ],
  "routePrefix": "/api/invoices",
  "routeFilters": ["auth"]
}
```

A plugin's routes compile into the same `route-manifest.php` as project routes. The **project overrides the plugin by default** — if you declare `GET /invoices` in your project, it takes precedence over the plugin's route of the same method and path.

**Route key precedence:**

| Source | Compiled | Override |
|---|---|---|
| Plugin route | First | By project route with same `METHOD /path` |
| Project route | Last | Overrides any plugin route with same key |

### Disabling Plugin Routes

The project can veto specific plugin routes via `proj.json` `routePolicy.disable`:

```json
{
  "routePolicy": {
    "disable": [
      "GET /register",              // One specific route (method + path)
      "auth.identity"               // All routes a module solves()
    ]
  }
}
```

Use this to hide unwanted plugin routes without forking the plugin. A disabled route becomes a 404; the project can optionally re-declare its own route on the freed key.

::: info
See [Routing: Disabling Plugin Routes](/routing/basics) for the full reference.
:::

## CLI Commands and Jobs

Plugins declare their CLI commands in `module.json` `commands[]`. The CLI registers each declared handler class when it starts; register in `Provider::boot()` (`$cli->command(...)` or `$cli->defer(...)`) only when a command needs services from the module's own scoped container:

```json
{
  "commands": [
    "InvoiceGenerateCommand",
    { "name": "invoice:export", "handler": "App\\Commands\\ExportCommand" }
  ]
}
```

Jobs are declared similarly:

```json
{
  "jobs": [
    "SendEmailJob",
    {
      "name": "invoices.nightly-sync",
      "handler": "SyncNightlyJob",
      "queue": "maintenance"
    }
  ]
}
```

Both are only available if the plugin is installed (declared in `withModules([...])`). A missing command or job is reported as "not found", not hidden silently.

## Plugin vs Project Distinction

| Aspect | Plugin | Project |
|---|---|---|
| Location | `plugins/Vendor/Name/` | Project root (or `projects/admin/`) |
| Module file | `plugins/Vendor/Name/module.json` | `proj.json` |
| Namespace | `Plugins\Vendor\Name\` | `Project\` or `App\` (anything except Plugins) |
| Declared by | Kernel (automatic scan of `plugins/`) | `Kernel::withProjectPath()` / manually |
| Routes, commands, jobs | Declared in `module.json` | Declared in `proj.json` or `withRoutes()` |
| Overrides | No — can only be disabled | Yes — project overrides plugins by default |

## Plugin Documentation

Every plugin should document itself. The kernel does not enforce or read a separate documentation file — you are responsible for providing clear README, API docs, and examples in your plugin's repository or in a companion doc file.

The `documentation` field in `module.json` is a short description shown when the plugin is enabled or disabled:

```json
{
  "documentation": "Invoice generation, PDF export, and audit trail."
}
```

### What You Must Document

- **What domain it solves** — e.g., "invoice.generation — handles invoice creation, PDF export, and email delivery."
- **What it requires** — Which other plugins or ports it depends on.
- **What it exposes** — The public contracts other modules can use.
- **Configuration** — Every env var in `config[]`, its type, default, and purpose.
- **Routes** (if any) — What endpoints it adds and what they do.
- **Events** (if any) — What integration events it emits and subscribes to.
- **Usage examples** — How to use it in a module that requires it.

The kernel's own documentation lives in each plugin's repository, not in this kernel guide. The maintainers of a plugin own its docs.

## Checking if a Plugin is Installed

Use `plugin_installed()` or `Plugins::installed()` to check at runtime:

```php
if (plugin_installed('email.delivery')) {
    $this->queue(new SendEmailJob($invoice));
} else {
    $this->logWarning('Email delivery plugin not installed');
}
```

Or use `Plugins::ensure()` to fail loudly:

```php
Plugins::ensure('email.delivery', 'pdf.generation');
// Throws KernelException if either is missing
```

::: danger
Do NOT check for a plugin by trying to `instanceof` a class or catching a "class not found" error. Plugins may not be autoloaded if they're not installed, so the check would fail. Use the `Plugins` class instead.
:::

## Common Mistakes

### Mistake: Plugin requires a kernel change

**Wrong:** "The plugin won't work unless we modify `src/Kernel/`."

A correctly written plugin needs zero kernel changes. If a plugin requires a kernel edit, it is wrong — report it to the plugin maintainers, or write the missing port/contract yourself and publish it for others to use. The kernel's job is to be stable and unchanging; plugins' job is to adapt to it.

### Mistake: Importing internal plugin classes from outside the plugin

**Wrong:**

```php
// In another module
use Plugins\Invoice\Infrastructure\Persistence\InvoiceRepository;  // ✗

$repo = $container->make(InvoiceRepository::class);  // ✗
```

Import only the plugin's published contracts, declared in `exposes[]`:

**Correct:**

```php
use Plugins\Invoice\API\Contracts\InvoiceServiceContract;  // ✓

$service = $container->make(InvoiceServiceContract::class);  // ✓
```

### Mistake: Plugin declared but not in `withModules()`

**Wrong:**

```php
// Trying to resolve a contract from a plugin that is not installed
$service = $container->make(SomePluginContract::class);  // throws
```

Always register a plugin in `withModules([...])` before your code tries to use it. The boot validates `requires[]`, so an unregistered plugin's contract will fail to bind.

### Mistake: Using `plugin_installed()` to check for hard dependencies

**Wrong:**

```php
// In a module that REQUIRES the plugin
public function boot(...): void
{
    if (!plugin_installed('email.delivery')) {
        throw new RuntimeException('Email delivery plugin is required!');
    }
}
```

Instead, declare it in your `module.json` `requires[]`. The boot fails with a descriptive message.

**Correct:**

```json
{
  "requires": ["email.delivery"]
}
```

## Source

- [`src/Kernel/Support/Plugins.php`](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Support/Plugins.php)
- [`src/Kernel/Support/helpers.php`](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Support/helpers.php) — `plugin_installed()` function
- [`src/Kernel/Boot/Stages/CompileModuleFilesStage.php`](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Boot/Stages/CompileModuleFilesStage.php)
- [`src/Kernel/Boot/Stages/LoadModuleFilesStage.php`](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Boot/Stages/LoadModuleFilesStage.php)
