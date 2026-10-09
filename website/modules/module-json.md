# module.json Reference

Every module declares its contract with the kernel in `module.json` — a manifest that lives in the module's root directory alongside `Provider.php`. The kernel reads this file at boot time to compile manifests for routing, services, jobs, commands, views, languages, and configuration. This is the **source of truth**; your `Provider.php` method returns must mirror these declarations exactly.

::: warning
Every key the kernel reads is validated at boot. A typo or invalid value fails the boot with a descriptive error message — never silently. That is by design: config mistakes are caught immediately, not discovered as silent 404s or unbound contracts at request time.
:::

## Module Identity

These keys identify your module and declare what it offers and what it needs.

| Key | Type | Required | Stage | Example |
|---|---|---|---|---|
| `name` | string | Yes | Most stages | `"invoice"` |
| `version` | string | Yes | — | `"1.0.0"` |
| `solves` | string | Yes | `CompileServiceManifestStage` | `"invoice.generation"` |
| `type` | string | No | — | `"module"` (default) |
| `requires` | `string[]` | No | `CompileServiceManifestStage` | `["database.query"]` |
| `exposes` | `string[]` | No | `CompileServiceManifestStage` | `["InvoiceServiceContract"]` |

### `name`

A short kebab-case identifier for this module. Used as the default namespace for views and language translations, and shown in `hkm plugins` listings.

```json
{
  "name": "invoice"
}
```

- **Unique across all installed modules** (recommended, not enforced).
- Used to prefix plugin messages in `config[]` declarations when `hkm plugins enable` seeds env vars.

### `version`

Semantic version (major.minor.patch). Currently informational, but reserved for future conflict detection.

```json
{
  "version": "1.0.0"
}
```

### `solves`

The single business domain this module owns. Used as a unique identifier in the dependency graph. Other modules reference it in their `requires[]`.

```json
{
  "solves": "invoice.generation"
}
```

- **Must be globally unique** across all installed modules. A duplicate fails the boot.
- Dot-separated lowercase convention: `vendor.domain` or `domain.subdomain`.
- Must exactly match the `solves()` method return in your `Provider.php`.

::: tip
See [The ModuleContract Interface](/modules/module-contract#solves-string) for the full rules.
:::

### `type`

The module type. Currently recognized values are `"module"` (default), `"job"`, and `"command"`.

```json
{
  "type": "module"
}
```

This is primarily informational at boot but may influence future tooling. Omit it; `"module"` is the default.

### `requires`

List of module domains this module depends on. The kernel validates every entry against registered modules and fails the boot if any are unknown.

```json
{
  "requires": ["database.query", "view.rendering"]
}
```

- Each entry must be another module's `solves` value.
- An unknown entry (typo or missing plugin in `withModules([...])`) fails with a descriptive message listing what domains ARE registered.
- **Port classes (DatabasePort, MailPort, etc.) are NOT listed** — they resolve via `CoreContainer`.
- Must exactly match the array returned by `Provider::requires()`.

### `exposes`

List of contracts this module publishes to other modules. Only listed classes can be resolved by modules that declare this module in their `requires[]`.

```json
{
  "exposes": ["App\\Invoices\\API\\Contracts\\InvoiceServiceContract"]
}
```

- Fully qualified class names or short names (if the class is imported in the context where the manifest is read).
- The class must exist and implement your service contract interface.
- Must exactly match the array returned by `Provider::exposes()`.

## Routing

Routes and route grouping keys compile into `route-manifest.php`, `route-index.php` (the matcher), and `route-names.php` (for URL generation). For the complete routing reference including path parameters, domain grouping, and the full grammar, see [Routing](/routing/basics).

| Key | Type | Required | Stage | Notes |
|---|---|---|---|---|
| `routes` | array | No | `CompileRouteManifestStage` | List of route objects |
| `groups` | array | No | `CompileRouteManifestStage` | Nested route groups |
| `routePrefix` | string | No | `CompileRouteManifestStage` | Prepended to every path |
| `routeFilters` | `string[]` | No | `CompileRouteManifestStage` | Prepended to every route's filters |
| `routeRequires` | `string[]` | No | `CompileRouteManifestStage` | Added to every route's requires |
| `routeName` | string | No | `CompileRouteManifestStage` | Prefixed onto every route's name |
| `routeDomain` | `string \| string[]` | No | `CompileRouteManifestStage` | Host(s) this route answers on |
| `routeSubdomain` | `string \| string[]` | No | `CompileRouteManifestStage` | Subdomain(s) under the host |

### `routes`

A list of HTTP route objects. Each route **must have** `method`, `path`, and `handler`; everything else is optional.

```json
{
  "routes": [
    {
      "method": "GET",
      "path": "/invoices",
      "handler": "Plugins\\Invoice\\Http\\InvoiceController@index",
      "name": "invoice.index"
    },
    {
      "method": "POST",
      "path": "/invoices",
      "handler": "Plugins\\Invoice\\Http\\InvoiceController@create",
      "filters": ["auth", "throttle:60,1"]
    },
    {
      "method": "GET",
      "path": "/invoices/{id:num}",
      "handler": "Plugins\\Invoice\\Http\\InvoiceController@show"
    }
  ]
}
```

**Route entry keys:**

| Key | Type | Required | Example | Notes |
|---|---|---|---|---|
| `method` | string | Yes | `"GET"` | Upper-cased automatically; `HEAD` served by `GET` |
| `path` | string | Yes | `"/invoices/{id:num}"` | Must start with `/`; cannot be relative |
| `handler` | string | Yes | `"InvoiceController@index"` | Exactly one `@`; `Full\Class@method` |
| `name` | string | No | `"invoice.index"` | Application-wide unique; two routes cannot share a name |
| `filters` | `string[]` | No | `["auth", "throttle:60,1"]` | Route opts into these filters |
| `requires` | `string[]` | No | `["view.rendering"]` | Additional module domains for THIS route only |
| `domain` | `string \| string[]` | No | `"admin.example.com"` | Host(s) this route answers on; overrides module-wide |
| `subdomain` | `string \| string[]` | No | `["api", "admin"]` | Subdomain(s); can be bare (every domain) or attached |
| `faces` | `string[]` | No | `["admin"]` | Hide this route on other faces; requires route_face attribute |

**Boot failures (each validated at boot):**

- Path does not start with `/` — relative paths could never match.
- Path contains duplicate or invalid parameter names — `{a}/{a}` or `{2fa}`.
- Handler lacks exactly one `@` or the class/method do not exist (if `ROUTE_VERIFY_HANDLERS` is set).
- Unknown filter alias in `filters[]` (if `ROUTE_STRICT_FILTERS` is true).
- Unknown `requires[]` domain.
- Duplicate route name — names are application-wide unique.

### `groups`

Nested route groups. Every group is expanded at boot into flat routes with inherited prefix, filters, domain, and name. Groups may nest up to 16 levels deep.

```json
{
  "groups": [
    {
      "prefix": "/admin",
      "filters": ["shield"],
      "name": "admin.",
      "requires": ["audit.trail"],
      "routes": [
        { "method": "GET", "path": "/stats", "handler": "Plugins\\Invoice\\Http\\AdminController@stats" }
      ],
      "groups": [
        {
          "prefix": "/users",
          "routes": [
            { "method": "POST", "path": "/{id}/promote", "handler": "Plugins\\Invoice\\Http\\AdminController@promote" }
          ]
        }
      ]
    }
  ]
}
```

**Group inheritance rules:**

| Key | Inheritance | Conflict resolution |
|---|---|---|
| `prefix` | Concatenated outward-in: `/admin` + `/users` = `/admin/users` | N/A |
| `name` | Concatenated outward-in: `admin.` + `users.` = `admin.users.` | N/A |
| `filters` | Merged, de-duplicated by alias | Route's `throttle:5,1` replaces group's `throttle:60,1` |
| `requires` | Union (no duplicates) | N/A |
| `domain` / `subdomain` | Inner group overrides outer | N/A |

### `routePrefix`

Module-wide path prefix prepended to every route in this module.

```json
{
  "routePrefix": "/api/v1"
}
```

Equivalent to wrapping every route in a group with `"prefix": "/api/v1"`. Saves repetition when every route shares a prefix.

### `routeFilters`

Module-wide filters merged into the front of every route's `filters[]`. A route re-declaring the same filter alias overrides it.

```json
{
  "routeFilters": ["auth"]
}
```

If a route declares `"filters": ["throttle:60,1"]`, the final filters are `["auth", "throttle:60,1"]`. If the route declares `"filters": ["auth"]` (resetting), the module-wide `auth` is still prepended, so it becomes `["auth", "auth"]` — but the filter registry de-duplicates by alias, so only one runs.

### `routeRequires`

Module-wide module domains added to every route's `requires[]`. A route can declare additional domains for just itself.

```json
{
  "routeRequires": ["view.rendering"]
}
```

### `routeName`

Module-wide name prefix applied to every route's `name`. A route without an explicit name stays unnamed (the prefix is not applied to unnamed routes).

```json
{
  "routeName": "invoice."
}
```

A route with `"name": "index"` becomes `"invoice.index"`. A route with no `name` key stays unnamed.

### `routeDomain` and `routeSubdomain`

Module-wide domain / subdomain grouping. Routes may also declare their own to override these. See [Route Domains](/routing/domains) for the complete rules.

```json
{
  "routeDomain": "admin.example.com"
}
```

## Service, Job, and Command Declarations

| Key | Type | Required | Stage | Notes |
|---|---|---|---|---|
| `emits` | `string[]` | No | — | Integration event names this module dispatches |
| `commands` | array | No | `CompileCommandManifestStage` | CLI commands this module provides |
| `jobs` | array | No | `CompileJobManifestStage` | Background jobs this module provides |
| `schedule` | array | No | `CompileScheduleManifestStage` | Scheduled tasks (cron jobs) |

### `emits`

The integration event names this module dispatches. The kernel's boot pipeline does not read it, but tooling does: [ground](/packages/ground) subscribes its event recorder to exactly these names (an undeclared event is never recorded), and `plugin:check` reports drift between `emits[]` and the code. Keep it complete.

```json
{
  "emits": ["invoice.created", "invoice.paid"]
}
```

Subscriptions are not declared in `module.json`: a module subscribes in `Provider::boot()` with `$events->subscribe('payment.succeeded', Listener::class)`.

### `commands`

List of CLI commands this module provides. Each entry is a string (command name = handler class name) or an object with `name` and `handler`.

::: tip Declared commands are registered for you
When the CLI starts, `CliPipeline` registers every `commands[]` entry whose `handler` is a loadable class extending `AbstractCommand` **and whose constructor takes no parameters, not even optional ones**. For those you do not also need `$cli->command()` in `boot()`. If `boot()` does register the same class or name, `boot()`'s registration wins and nothing is added twice.

A command with constructor dependencies is never registered from `commands[]`, even when the `CoreContainer` could autowire it: the kernel cannot know which container those dependencies should come from, and a command built against the wrong `DatabasePort` looks like it works. Register it in `boot()`: `$cli->command(MyCommand::class)` for port dependencies, or `$cli->defer(...)` for services from the module's own scoped container.

A bare-string entry is a name only, with no handler class to load. It is listed in the manifest but cannot be registered, so give real commands the object form.
:::

```json
{
  "commands": [
    "InvoiceGenerateCommand",
    { "name": "invoice:export", "handler": "App\\Commands\\ExportInvoicesCommand" }
  ]
}
```

**Command entry keys:**

| Key | Type | Default | Example |
|---|---|---|---|
| `name` | string | Required | `"invoice:generate"` |
| `handler` | string | Same as `name` | `"InvoiceModule\\Commands\\GenerateCommand"` |

The handler class must extend `AlfacodeTeam\PhpIoCli\AbstractCommand` and live in an autoloadable namespace.

### `jobs`

List of background jobs this module provides. Each entry is a string (job name = handler class) or an object with additional configuration.

```json
{
  "jobs": [
    "SendInvoiceEmailJob",
    {
      "name": "invoices.nightly-rebuild",
      "handler": "InvoiceModule\\Jobs\\NightlyReindexJob",
      "queue": "maintenance",
      "retry": { "max": 3, "strategy": "exponential", "jitter": true },
      "timeout": 300
    }
  ]
}
```

**Job entry keys:**

| Key | Type | Default | Example | Notes |
|---|---|---|---|---|
| `name` | string | Required | `"invoices.nightly"` | Application-wide unique |
| `handler` | string | Same as `name` | `"InvoiceJob"` | Class name or fully qualified |
| `queue` | string | `"default"` | `"maintenance"` | Queue to push job into |
| `retry` | object | null | See below | Retry configuration for this job |
| `timeout` | int | null | `30` | Seconds a single attempt may run |

**Job-level retry object:**

```json
{
  "retry": {
    "max": 5,
    "strategy": "exponential",
    "base": 1,
    "jitter": true
  }
}
```

| Key | Type | Default | Example | Notes |
|---|---|---|---|---|
| `max` | int | 3 | `5` | Minimum 1 (a job with 0 attempts cannot run) |
| `strategy` | string | `"exponential"` | `"linear"` or `"fixed"` | Retry backoff strategy |
| `base` | int | 1 | `2` | Base delay in seconds (strategy-dependent) |
| `jitter` | bool | false | true | Add random jitter to retry delays |

An undeclared retry compiles to null, so the worker uses its loop-wide defaults. A module.json with `"type": "job"` at the top level declares defaults for all its jobs.

### `schedule`

List of scheduled tasks (cron jobs). Each task runs a job or command on a cron expression.

```json
{
  "schedule": [
    {
      "name": "invoices.nightly-rebuild",
      "at": "0 2 * * *",
      "job": "invoices.nightly-rebuild",
      "queue": "maintenance",
      "withoutOverlapping": true,
      "timezone": "Africa/Nairobi"
    },
    {
      "name": "cache.prune",
      "at": "@hourly",
      "command": "cache:prune --stale"
    }
  ]
}
```

**Scheduled task keys:**

| Key | Type | Required | Example | Notes |
|---|---|---|---|---|
| `name` | string | Yes | `"invoices.nightly"` | Application-wide unique |
| `at` or `cron` | string | Yes | `"0 2 * * *"` | Five-field cron or alias (`@daily`, `@hourly`) |
| `job` | string | One of these | `"invoices.nightly"` | Job name to push onto queue |
| `command` | string | One of these | `"cache:prune"` | CLI command to run in-process |
| `queue` | string | `"default"` | `"maintenance"` | Queue for the job (ignored for commands) |
| `payload` | object | `{}` | `{"limit": 100}` | Extra data to pass to the job |
| `withoutOverlapping` | bool | false | true | Prevent overlapping runs (mutual exclusion lock) |
| `expiresAfter` | int | 3600 | 7200 | Seconds the overlap lock lives (must be > 60) |
| `timezone` | string | (system) | `"Africa/Nairobi"` | IANA timezone for the cron schedule |

**Boot failures:**

- Invalid cron expression — e.g., hour 25, month 13.
- Declaring both `job` and `command` — a task does exactly one thing.
- Declaring neither `job` nor `command` — the task would run nothing.
- Unknown timezone — not in PHP's `DateTimeZone::listIdentifiers()`.

## Views and Languages

Views and language catalogues compile into `view-manifest.php` and `lang-manifest.php`. Both follow an identical priority model where the project overrides plugins by default, and lower numeric `priority` wins.

| Key | Type | Required | Stage | Notes |
|---|---|---|---|---|
| `views` | `string \| object \| array` | No | `CompileViewManifestStage` | View template directories |
| `lang` | `string \| object \| array` | No | `CompileLangManifestStage` | Language/translation directories |

### `views`

Register view template paths. Can be a simple string (directory path), a single object, or an array of objects.

```json
{
  "views": "resources/views"
}
```

Or with full configuration:

```json
{
  "views": [
    {
      "path": "resources/views",
      "namespace": "invoice",
      "priority": 100,
      "global": true
    }
  ]
}
```

**View source keys:**

| Key | Type | Default | Example | Notes |
|---|---|---|---|---|
| `path` | string | Required | `"resources/views"` | Relative to module dir or absolute |
| `namespace` | string | `module.name` | `"invoice"` | Used in `render('invoice::view')` |
| `priority` | int | 100 (plugins) / 0 (project) | `50` | Lower wins; project defaults to 0 |
| `global` | bool | true | false | Expose in plain `render('view')` cascade |

**Resolution:** A plain `render('welcome')` walks the global cascade (project first). A namespaced `render('invoice::welcome')` checks the project's `invoice/` folder first, then the namespace.

### `lang`

Register language/translation directories. Same shape and rules as `views`.

```json
{
  "lang": "resources/lang"
}
```

With full configuration:

```json
{
  "lang": {
    "path": "resources/lang",
    "namespace": "invoice",
    "priority": -1,
    "global": true
  }
}
```

**Language source keys:** Same as views.

**Priority note:** A plugin setting `"priority": -1` explicitly preempts the project — the only way a plugin overrides the project for translations.

## Module Files and Configuration

| Key | Type | Required | Stage | Notes |
|---|---|---|---|---|
| `files` | `string[]` | No | `CompileModuleFilesStage` | PHP files to require at boot (helpers, global functions) |
| `config` | array | No | `ValidateConfigStage` | Environment variables this module reads |
| `documentation` | string | No | — | Human-readable description (shown on enable/disable) |

### `files`

List of plain PHP files to require at boot. Used for global functions and constants that class autoloaders cannot reach.

```json
{
  "files": ["helpers/InvoiceHelpers.php", "functions/Transformers.php"]
}
```

**Rules:**

- Paths are relative to the module directory (where `Provider.php` lives).
- A declared file that does not exist **fails the boot**.
- When `files` is omitted, the kernel falls back to your `composer.json` `"autoload.files"`.
- Files are required via `require_once`, so redefining them in a second build is safe.

::: tip
See [Module Files](/modules/plugins#module-files-loading-global-functions) for the complete details.
:::

### `config`

List of environment variables this module reads. The kernel validates each one at boot — all required vars must be present, and all declared vars must satisfy their type constraints.

```json
{
  "config": [
    "INVOICE_CURRENCY",
    {
      "key": "INVOICE_TAX_RATE",
      "type": "float",
      "required": false,
      "default": 0.18
    }
  ]
}
```

**Config entry:**

| Format | Description | Notes |
|---|---|---|
| String | `"VAR_NAME"` | Required string (read with `env('VAR_NAME')`) |
| Object | `{"key": "VAR_NAME", "type": "...", "required": ...}` | Full specification |

**Config object keys:**

| Key | Type | Default | Example | Notes |
|---|---|---|---|---|
| `key` | string | Required | `"INVOICE_CURRENCY"` | Env var name |
| `type` | string | `"string"` | `"float"` | `string`, `int`, `integer`, `float`, `bool`, `boolean` |
| `required` | bool | true | false | Whether var must be present and non-empty |
| `default` | any | — | `0.18` | Default value (shows in .env on `hkm plugins enable`) |

**Validation at boot:**

- Required vars (`required: true`) must be present in `.env` and non-empty. An empty string `""` is treated as missing.
- Optional vars (`required: false`) may be absent or empty; validation skips them.
- Types are validated: `"true"`, `"false"`, `"1"`, `"0"`, `"yes"`, `"no"` are valid bools; numeric strings are valid for int/float.

**On `hkm plugins enable`:**

- Required vars with no default are seeded as `VAR_NAME=` (empty, active) — the boot fails until a real value is supplied.
- Required vars with a default are seeded as `VAR_NAME=value` (active).
- Optional vars with no default are seeded as `# VAR_NAME=` (commented out).

### `documentation`

A human-readable description of what this module does. Shown when running `hkm plugins enable` or `hkm plugins disable`.

```json
{
  "documentation": "Invoice generation and management — PDF export, email delivery, and audit trail."
}
```

## Example: A Complete module.json

```json
{
  "name": "invoice",
  "version": "1.0.0",
  "solves": "invoice.generation",
  "type": "module",

  "requires": ["database.query", "pdf.generation"],
  "exposes": ["App\\Invoices\\API\\Contracts\\InvoiceServiceContract"],

  "routePrefix": "/api/invoices",
  "routeFilters": ["auth"],
  "routeName": "invoice.",
  "routes": [
    {
      "method": "GET",
      "path": "",
      "handler": "Plugins\\Invoice\\Http\\InvoiceController@index",
      "name": "index"
    },
    {
      "method": "POST",
      "path": "",
      "handler": "Plugins\\Invoice\\Http\\InvoiceController@create",
      "filters": ["throttle:60,1"]
    },
    {
      "method": "GET",
      "path": "/{id:num}",
      "handler": "Plugins\\Invoice\\Http\\InvoiceController@show",
      "name": "show"
    }
  ],

  "groups": [
    {
      "prefix": "/admin",
      "filters": ["shield"],
      "name": "admin.",
      "routes": [
        {
          "method": "GET",
          "path": "/audit",
          "handler": "Plugins\\Invoice\\Http\\InvoiceAdminController@audit"
        }
      ]
    }
  ],

  "emits": ["invoice.created", "invoice.paid"],

  "commands": ["InvoiceGenerateCommand"],

  "jobs": [
    {
      "name": "invoices.send-email",
      "handler": "SendInvoiceEmailJob",
      "queue": "emails",
      "retry": { "max": 3, "strategy": "exponential" },
      "timeout": 60
    }
  ],

  "schedule": [
    {
      "name": "invoices.nightly-rebuild",
      "at": "0 2 * * *",
      "job": "invoices.send-email",
      "timezone": "Africa/Nairobi"
    }
  ],

  "views": [
    {
      "path": "resources/views",
      "namespace": "invoice",
      "priority": 100
    }
  ],

  "lang": [
    {
      "path": "resources/lang",
      "namespace": "invoice"
    }
  ],

  "files": ["helpers/InvoiceHelpers.php"],

  "config": [
    "INVOICE_CURRENCY",
    {
      "key": "INVOICE_TAX_RATE",
      "type": "float",
      "required": false,
      "default": 0.18
    }
  ],

  "documentation": "Invoice generation, PDF export, and audit trail."
}
```

## Boot Stages and Validation Order

The kernel reads `module.json` across twelve compile stages, each validating different keys:

1. **`ValidateConfigStage`** — Checks `config[]` env vars exist and have correct types.
2. **`DetectConflictsStage`** — Ensures no two modules share the same `solves` domain.
3. **`DetectCyclesStage`** — Ensures `requires[]` dependencies form no circular graphs.
4. **`CompileServiceManifestStage`** — Reads `name`, `solves`, `requires[]`, `exposes[]`.
5. **`CompileRouteManifestStage`** — Reads `routes[]`, `groups[]`, and all `route*` keys.
6. **`CompileViewManifestStage`** — Reads `views` and `name` (default namespace).
7. **`CompileLangManifestStage`** — Reads `lang`.
8. **`CompileJobManifestStage`** — Reads `jobs[]` (with inline `retry`/`timeout`).
9. **`CompileCommandManifestStage`** — Reads `commands[]`; `CliPipeline` registers them when the CLI starts.
10. **`CompileScheduleManifestStage`** — Reads `schedule[]` (parses and validates cron).
11. **`CompileConfigManifestStage`** — Reads the module's `config/*.php` files (not a `module.json` key).
12. **`CompileModuleFilesStage`** — Reads `files[]` or falls back to `composer.json` `autoload.files`.

Then `LoadModuleFilesStage` requires the compiled helper files, and `RegisterPortsStage` / `BindSecurityStage` validate port bindings and security layers (these three run even on a cached boot).

A failure in any stage stops the boot with a descriptive error message. You do not get to the next stage until all modules pass the current one.

::: tip
Use `php artisan make:module` (or equivalent tooling for your project) to scaffold a new module with a valid `module.json` template. Hand-editing is error-prone — the manifest is machine-generated, not hand-written.
:::

## Source

- [`templates/plugin/module.json`](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/templates/plugin/module.json)
- [`src/Kernel/Boot/Stages/`](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Boot/Stages/)
- [`src/Kernel/Boot/ManifestReader.php`](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Boot/ManifestReader.php)
