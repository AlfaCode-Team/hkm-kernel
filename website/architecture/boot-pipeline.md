# Boot Pipeline

The BootPipeline runs once when `Kernel::build()` is called. It validates configuration, compiles manifests from `module.json` and `config/*.php`, and produces the data structures the kernel uses at request time. This page covers every stage in execution order, what each produces, and the `BOOT_CACHE` optimization that eliminates 2ms+ of latency per request under PHP-FPM.

## What the Pipeline Produces

Stages write these manifest files to `var/cache/manifests/`:

| File | Written by | Purpose | Loaded by |
|---|---|---|---|
| `service-manifest.php` | CompileServiceManifestStage | Dependency graph: module domain → provider + requires | DependencyGraphCalculator |
| `route-manifest.php` | CompileRouteManifestStage | Flat routes: "METHOD path" → handler + filters + metadata | HTTP pipeline, UrlGenerator (fallback), project-layer tooling such as `RouteCatalog` |
| `route-index.php` | CompileRouteManifestStage | Compiled route matcher: buckets + regex per dynamic route | HTTP ResolveStage |
| `route-names.php` | CompileRouteManifestStage | Route name → `{path, method, domain}`: tiny, for UrlGenerator | UrlGenerator |
| `view-manifest.php` | CompileViewManifestStage | View paths: global cascade + namespaced | a view plugin's renderer (the kernel only writes it) |
| `lang-manifest.php` | CompileLangManifestStage | Language file paths: project-first cascade | a translation plugin (the kernel only writes it) |
| `job-manifest.php` | CompileJobManifestStage | Background jobs: name → handler + module + retry + timeout | WorkerLoop |
| `command-manifest.php` | CompileCommandManifestStage | CLI commands: name → handler + module | CliPipeline (when the CLI starts) |
| `schedule-manifest.php` | CompileScheduleManifestStage | Scheduled tasks by name: cron + job/command + options | Scheduler |
| `config-manifest.php` | CompileConfigManifestStage | Configuration: config/\*.php merged, project-first | ConfigRepository |
| `files-manifest.php` | CompileModuleFilesStage | Helper files to require_once | LoadModuleFilesStage |
| `boot-stamp.php` | Kernel (after run) | Cache validation: file signatures + config hash | BootStamp::read() |

## Pipeline Stages (Execution Order)

### Compile Stages (1–12)

These stages read `module.json` and configuration files, then write manifests. With `BOOT_CACHE=1`, all 12 stages are skipped when the stamp says the manifests are current. Each stage has access to a shared `ManifestReader` whose module.json cache is warm after the first stage reads it.

#### Stage 1: ValidateConfigStage

**Purpose:** Ensure every env var declared in a module's `module.json` `config[]` exists and has the correct type. (`proj.json` declares no env vars; this stage reads module manifests only.)

**Validates:**
- Each entry is either a bare `"VAR_NAME"` string or an object `{ "key": "VAR_NAME", "type": ..., "required": ... }`.
- A value is **missing** when `env()` returns `null` or `''`. An empty `KEY=` line counts as missing.
- `required` defaults to `true`. Only `"required": false` makes a key optional. A `default` does **not**: the kernel never applies it at runtime, it is only the value `hkm plugins enable` writes into `.env`.
- When present, a value is type-checked: `int`/`integer` (digits, optional leading `-`), `float` (`is_numeric`), `bool`/`boolean` (`true`, `false`, `1`, `0`, `yes`, `no`, case-insensitive). Any other type is unchecked.
- Every problem across every module is collected and reported at once, not just the first.

**Fails:** throws `BootException("Configuration invalid. Missing: X (required by invoice) ... Invalid: Y must be float (declared by invoice)")`, which `BootPipeline` rethrows as `BootFailureException("Boot failed at [...ValidateConfigStage]: ...")`.

#### Stage 2: DetectConflictsStage

**Purpose:** Ensure no two modules claim the same domain.

**Validates:**
- Reads each module's `solves` value from `module.json`.
- Two modules with the same `solves` is a configuration error (a module is duplicated, or the domain is wrong).

**Fails:** BootFailureException if a `solves` domain appears twice.

#### Stage 3: DetectCyclesStage

**Purpose:** Ensure module dependencies have no circular chains.

**Validates:**
- Builds a dependency graph from each module's `requires[]`.
- Detects cycles using depth-first search.
- A → B → C → A is impossible.

**Fails:** CircularDependencyException listing the cycle path.

#### Stage 4: CompileServiceManifestStage

**Purpose:** Build the service manifest — a dependency index used by DependencyGraphCalculator to resolve module graphs.

**Reads:**
- Each module's `solves` and `requires[]` from `module.json`.
- Each project route's `requires[]` (extra domains that route needs).

**Writes:** `service-manifest.php`

```php
// Typical output
return [
    'services' => [
        'invoice.generation' => [
            'module' => InvoiceModule::class,
            'requires' => ['database.management'],
        ],
        'database.management' => [
            'module' => DatabaseModule::class,
            'requires' => [],
        ],
        '__project__' => [
            'module' => null,  // synthetic scope for project routes
            'requires' => [],
        ],
    ],
];
```

**Used by:** `DependencyGraphCalculator::resolve()` to build the graph for each request.

#### Stage 5: CompileRouteManifestStage

**Purpose:** Expand routes from `module.json` and project routes into a flat manifest, validate domains, and apply the disable policy.

**Reads:**
- Each module's `routes[]` and `groups[]` from `module.json`.
- Project routes from `Kernel::withRoutes()`.
- Project route groups from `Kernel::withRouteGroups()`.
- Project route policy (disable + allow lists) from `Kernel::withRoutePolicy()` / `Kernel::withRouteAllowPolicy()`.
- Project domains from `Kernel::withProjectDomains()`.

**Processes:**
1. Expands all route groups into flat routes, concatenating prefixes, names, filters, and requires.
2. Applies the disable policy to plugin routes (removes them from the manifest).
3. Applies the allow policy to plugin routes (keeps only matching ones).
4. Validates that grouped routes' domains are registered in `withProjectDomains()` (unless a bare subdomain).
5. Detects duplicate route keys (METHOD + path + domain).

**Writes:**
- `route-manifest.php` — flat "METHOD[@domain] /path" → `{handler, filters, module, requires, …}`
- `route-index.php` — compiled matcher: per-HTTP-method first-segment buckets, per-dynamic-route anchored regex
- `route-names.php` — route name → `{path, method, domain}`

**Fails:** BootFailureException if a route domain is not registered, or a route name is duplicated.

#### Stage 6: CompileViewManifestStage

**Purpose:** Build the view path cascade (project-first, then plugins by priority).

**Reads:**
- Each module's `views` declaration from `module.json`.
- Project views from `proj.json` `views`.

**Writes:** `view-manifest.php`

```php
return [
    'global'     => [
        'priority' => 0 => '/project/views',
        'priority' => 100 => '/plugin-a/views',
        'priority' => 100 => '/plugin-b/views',
    ],
    'namespaces' => [
        'tasks' => ['/plugin-tasks/views'],
        'auth'  => ['/plugin-auth/views'],
    ],
];
```

**Resolution:** the view plugin's renderer (for example a `PhpViewRenderer`) walks `global` (project first, plugins lower priority) for plain names, and checks namespace dirs first when a view is namespaced (`tasks::welcome`).

#### Stage 7: CompileLangManifestStage

**Purpose:** Build the language file cascade (same pattern as views).

**Reads:**
- Each module's `lang` declaration from `module.json`.
- Project language paths from `proj.json` `lang`.

**Writes:** `lang-manifest.php` (same structure as view-manifest.php).

#### Stage 8: CompileJobManifestStage

**Purpose:** Build the background-job manifest, keyed by job name, with each job's normalised retry and timeout.

**Reads:** each module's `jobs[]`, plus a module-wide `retry` / `timeout` for modules whose `type` is `job`. Nothing is read from the job class itself.

**Writes:** `job-manifest.php`

```php
return [
    'invoices.send-email' => [
        'handler' => 'Shop\\Invoicing\\Jobs\\SendInvoiceEmail',
        'queue'   => 'emails',
        'module'  => 'Shop\\Invoicing\\Provider',   // provider class
        'solves'  => 'invoice.generation',
        'retry'   => ['max' => 3, 'strategy' => 'exponential', 'base' => 1, 'jitter' => false],  // or null
        'timeout' => 30,                                                                        // or null
    ],
];
```

**Used by:** `WorkerLoop`, to resolve the handler, build the job's container from `solves`, and pick its retry strategy and timeout. See [Workers & jobs](/background/workers-and-jobs#job-declarations-in-module-json).

#### Stage 9: CompileCommandManifestStage

**Purpose:** Index each module's `commands[]` by name.

**Writes:** `command-manifest.php`

```php
return [
    'invoice:export' => [
        'handler' => 'Shop\\Invoicing\\Cli\\ExportInvoices',
        'module'  => 'Shop\\Invoicing\\Provider',
        'solves'  => 'invoice.generation',
    ],
];
```

**Used by:** `CliPipeline`, when the CLI starts. After the commands registered in `boot()`, it adds every entry whose `handler` is a loadable `AbstractCommand` class not already registered (by class or name). A bare-name entry has no class and is skipped. See [CLI pipeline](/cli/cli-pipeline).

#### Stage 10: CompileScheduleManifestStage

**Purpose:** Validate every `schedule[]` entry and parse its cron expression at boot, so a typo fails the boot rather than a 2 a.m. tick.

**Writes:** `schedule-manifest.php`, keyed by task name

```php
return [
    'billing.sync-accounts' => [
        'name' => 'billing.sync-accounts', 'at' => '@hourly',
        'kind' => 'command', 'target' => 'billing:sync',
        'module' => 'Shop\\Billing\\Provider', 'solves' => 'billing.invoicing',
        'queue' => 'default', 'payload' => [],
        'withoutOverlapping' => false, 'expiresAfter' => 3600, 'timezone' => '',
    ],
];
```

**Used by:** `Scheduler`, on each `schedule:run` tick. See [Task scheduling](/background/scheduler).

#### Stage 11: CompileConfigManifestStage

**Purpose:** Merge `config/*.php` files from the project and every module, with the project always winning on key collisions.

**Reads:**
- `config/*.php` from the project root.
- `config/*.php` from each module.

**Merges:**
- Project files are loaded LAST so they override plugin files.
- Deep merge (arrays are merged, not replaced).

**Writes:** `config-manifest.php`

```php
return [
    'database.host' => 'localhost',
    'cache.ttl' => 3600,
    'invoice.tax_rate' => 0.18,
];
```

**Used by:** `ConfigRepository` (available via `config('key')` helper).

#### Stage 12: CompileModuleFilesStage

**Purpose:** Build a manifest of helper files each module wants to auto-load.

**Reads:**
- Each module's `files[]` from `module.json`.
- Each module's `composer.json` `autoload.files`.

**Writes:** `files-manifest.php`

```php
return [
    '/plugin-helpers/src/helpers.php',
    '/plugin-helpers/src/routes-helpers.php',
];
```

**Used by:** LoadModuleFilesStage to `require_once` them at boot.

### Always-Run Stages (Must Run on Every Build)

Even when compilation is cached, these stages always run. Skipping them would mean cached boots silently stop loading module helper files, which would change runtime behaviour.

#### LoadModuleFilesStage

**Purpose:** Load module helper files (require_once) so global functions are defined.

**Reads:** `files-manifest.php` (written by CompileModuleFilesStage).

**Processes:**
- `require_once` every file listed, in order.
- Silently ignores missing files (in case a module's helpers are optional or moved).

**Fails:** Any PHP syntax error in a helper file fails here, but normal runtime errors.

**Note:** This stage runs AFTER compilation (if any) but BEFORE validation. On a cached boot, it runs first.

### Validation Stages (Always Run on Every Build)

These stages touch no disk and produce no manifest. They run on every build — a cached boot must still refuse a missing port or an unusable security layer.

#### Stage 13: RegisterPortsStage

**Purpose:** Verify the two ports the kernel cannot run without are bound.

**Checks:** that `DatabasePort` and `CachePort` are bound in the `CoreContainer`. No other port is checked: a missing `QueuePort`, `MailPort` and so on surfaces only when something resolves it.

**Fails:** `BootException("Missing port bindings: …")`, rethrown as `BootFailureException("Boot failed at [...RegisterPortsStage]: …")`.

#### Stage 14: BindSecurityStage

**Purpose:** Verify the security gateway has something to run.

**Checks:**
- at least **one** layer was passed to `withSecurity()`. An empty list fails the boot with "No security layers configured", so even an API with no CSRF needs a layer (the generated bootstrap registers `CsrfTokenLayer` with `/api` exempt);
- every layer implements `SecurityLayerContract`.

**Fails:** `BootException`, rethrown as `BootFailureException`.

## ManifestReader

A shared utility class that reads and caches `module.json` files. Each module is read once per build (not once per stage), and the cache is reused:

```php
$reader = new ManifestReader();
$moduleData = $reader->read(InvoiceModule::class);
// [
//     'name' => 'invoice',
//     'solves' => 'invoice.generation',
//     'requires' => ['database.management'],
//     'routes' => [...],
//     ...
// ]
```

At the end of the pipeline, the caller can ask: `$reader->files()` returns every `module.json` and `config/*.php` file that was read (used for `BOOT_CACHE` invalidation).

## ManifestWriter

A utility class that writes manifests to `var/cache/manifests/`:

```php
ManifestWriter::write('route-manifest.php', $data);
// Writes: var/cache/manifests/route-manifest.php
// Content: <?php return $data; (formatted PHP array literal)
```

Returns the full filesystem path, and creates `var/cache/manifests/` if it does not exist.

## BootException vs BootFailureException

- **`BootException`** — thrown by a stage when validation fails (missing config, circular dependencies, etc.).
- **`BootFailureException`** — thrown by the pipeline's run loop when a stage throws `BootException`. Wraps the stage name and the original error message.

In production, you see `BootFailureException` at the top of the stack trace. The root cause (which stage, what validation failed) is in the message.

## BOOT_CACHE: The 2ms Optimization

### The Problem

Under PHP-FPM, every request re-executes `bootstrap/app.php`, so `Kernel::build()` runs again. On a ~130-route application, the BootPipeline:

- Reads every `module.json` (130 files, parse JSON 130 times).
- Globs every `config/` directory (search for `*.php` files).
- Rewrites 10+ manifest files to `var/cache/manifests/`.

**Measured cost:** ~2 ms and ~150 KB of file writes per request. The output is byte-identical to the last request's.

### The Solution

`BootStamp` records a signature of everything the compilation touched, then skips compilation on the next request if the signature still matches. The kernel only needs to:

1. Hash the builder inputs (module list, routes, domains, policy).
2. Check the signature of every source file (module.json, config/*.php) by mtime+size.
3. Count *.php files in watched directories to detect adds/deletes.

**Measured speedup:** ~2 ms → ~0.02 ms, 86× cheaper.

### Enabling It

```php
// app/bootstrap/app.php — or .env
// BOOT_CACHE=true

// OR set it in code:
if (env('APP_ENV') === 'production') {
    $_ENV['BOOT_CACHE'] = 'true';  // or 'false', '0', '1', 'yes', 'no'
}

$kernel = Kernel::configure()
    ->...
    ->build();
```

`BootStamp::enabled()` reads `env('BOOT_CACHE')` and uses PHP's `filter_var(..., FILTER_VALIDATE_BOOL)` to parse it.

### What Gets Cached

When `BOOT_CACHE=1` and a compile runs successfully, `Kernel::build()` calls:

```php
BootStamp::write($stampHash, $reader->files(), $essentialModules);
```

The stamp records:

1. **`config` hash** — `hash('sha256', serialize([...builder inputs...]))` of module list, project routes, domains, disable policy, paths. Covers edits to `bootstrap/app.php` and `proj.json` without stat'ing them.
2. **`files`** — every `module.json` and `config/*.php` file the compile read, with mtime:size signature.
3. **`dirs`** — count of `*.php` in each watched config/ directory.
4. **`essentials`** — resolved essential-module classes.

On the next request, `BootStamp::read($stampHash)` checks:
- Does every sentinel file exist? (route-manifest.php, files-manifest.php — guards kernel upgrades)
- Does the config hash match?
- Does every file signature match? (mtime and size)
- Does every watched directory's file count match?

If all checks pass → `runValidationOnly()` (skip compilation, run always-stages + validation-stages). If any check fails → run full compilation.

### What Does NOT Get Cached

1. **Validation stages** — ports, security layers — always run.
2. **Always-run stages** — LoadModuleFilesStage — always runs.
3. **env var declarations** — ValidateConfigStage is skipped when cached. An env var that disappears is not re-detected until the cache is cleared.

This is the trade the flag buys: ~86× speedup in exchange for one rare edge case (an env var disappearing is not noticed until you redeploy and clear `var/cache/`).

### When to Clear It

**Clear `var/cache/manifests/` on deploy.** Your CI pipeline should include:

```bash
rm -rf var/cache/manifests/*
```

Or use a deployment tool that clears artifact caches automatically.

### Pitfalls

- **mtime granularity:** mtime has one-second resolution. Editing a file twice within one second can leave the cache stale. Fine in production; disable `BOOT_CACHE` while actively developing.
- **NFS/shared filesystems:** mtime checks are not reliable across network filesystems. If your app is deployed to a shared NFS mount, test with `BOOT_CACHE=1` before committing.
- **Hard clock resets:** If a deploy tool resets the system clock backwards, cached stamps become invalid (timestamps in the future). Rare, but possible.

### BootStamp Implementation Details

```php
public static function read(string $configHash): ?array
{
    // Sentinel check: guard kernel upgrades
    foreach (self::SENTINELS as $sentinel) {
        if (!is_file(Paths::cache($sentinel))) {
            return null;  // Cache miss → compile
        }
    }

    // Read the stamp file
    $stamp = ManifestReader::readCompiled(self::FILE);

    // Check config hash (builder inputs)
    if (($stamp['config'] ?? null) !== $configHash) {
        return null;  // Cache miss → compile
    }

    // Check file signatures (mtime:size)
    foreach ($stamp['files'] ?? [] as $path => $signature) {
        if (self::signature((string) $path) !== $signature) {
            return null;  // Cache miss → compile
        }
    }

    // Check directory counts (detects adds/deletes)
    foreach ($stamp['dirs'] ?? [] as $dir => $count) {
        if (self::countPhp((string) $dir) !== $count) {
            return null;  // Cache miss → compile
        }
    }

    return ['essentials' => $stamp['essentials'] ?? []];  // Cache hit
}
```

**Key point:** The hash is computed ONCE, before `resolveEssentialModules()`. That resolution turns proj.json "essentials" domains into provider classes, so a hash taken after would describe a different array and never match the next build.

## Entry Point: BootPipeline::run()

```php
$pipeline = new BootPipeline(
    $moduleClasses,
    $core,
    $securityLayers,
    $projectRoutes,
    $disabledRoutes,
    $projectGroups,
    $projectDomains,
    $reader,
    $allowedRoutes,
);

if (BootStamp::enabled() && $stamp = BootStamp::read($hash)) {
    // Cache hit
    $pipeline->runValidationOnly();
    // ($essentialModules reloaded from $stamp)
} else {
    // Cache miss
    $pipeline->run();  // all stages: compile + always + validate
    BootStamp::write($hash, $reader->files(), $essentialModules);
}
```

## Common Boot Failures

| Error | Cause | Fix |
|---|---|---|
| `Unknown essential module domain(s): 'tenancy.routing'.` | A domain in `withEssentialModules()` / proj.json `essentials` is no module's `solves` | Check the spelling, or add the module to `withModules()` |
| `Module conflict detected: Both [A\\Provider] and [B\\Provider] declare solves: 'invoice.generation'` | Two modules claim the same `solves` | Give one module a different domain |
| `Module [X\\Provider] is missing a 'solves' domain in module.json` | `solves` absent or empty | Add it |
| `Circular dependency detected: auth.identity -> user.management -> auth.identity` | A `requires[]` cycle | Remove one edge |
| `Unknown module.json requires[] entries — no registered module solves them: …` | A module's `requires[]` names a domain no module solves (typo, or the provider is missing from `withModules()`) | Fix the spelling or register the module |
| `Route in … requires unknown module domain [x].` | Same, in a route's `requires[]` | Same |
| `Missing port bindings: …DatabasePort…` | `withPorts()` has no `DatabasePort` (or `CachePort`) | Bind an adapter |
| `No security layers configured.` | `withSecurity()` was never called, or with `[]` | Register at least one layer |
| `Configuration invalid. Missing: X (required by invoice)` | A `config[]` env var is absent or empty | Set it in `.env` (or mark it `"required": false`) |

Every boot failure arrives wrapped as `BootFailureException("Boot failed at [<stage class>]: <message>")`.

`Service [x] not found in service-manifest.php` is **not** a boot failure: it is thrown at request time by the dependency-graph calculator when a manifest is stale (for example a `BOOT_CACHE` deploy that kept an old `var/cache/manifests/`). Clear the manifests and rebuild.


## Source

- [BootPipeline.php](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Boot/BootPipeline.php)
- [BootStamp.php](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Boot/BootStamp.php)
- [ManifestReader.php](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Boot/ManifestReader.php)
- [ManifestWriter.php](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Boot/ManifestWriter.php)
- [Stages/](https://github.com/AlfaCode-Team/hkm-kernel/tree/main/src/Kernel/Boot/Stages)
