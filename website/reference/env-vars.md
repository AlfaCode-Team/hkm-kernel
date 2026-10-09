# Environment Variables

Every environment variable read by the kernel (`src/`), the generated project bootstrap, and the first-party packages in `modules/`. Variables a plugin reads are declared in that plugin's `module.json` `config[]` and documented by the plugin.

Boolean flags are parsed with `FILTER_VALIDATE_BOOL`, so `1`, `true`, `on` and `yes` all enable a flag, and an empty value means "use the default".

::: tip Where to set them
Most variables are read through `env()`, which sees values loaded from the [`.env` cascade](/guide/configuration#the-env-cascade). The few marked **real env only** are read with `getenv()` before or outside that loader, so they must be in the actual process environment: shell, systemd unit, container, or PHP-FPM pool. A `.env` line will not reach them.
:::

## Kernel

### Application

| Variable | Default | Read by | Effect |
|---|---|---|---|
| `APP_KEY` | empty | `CsrfTokenLayer`, `UrlGenerator` | Default HMAC secret for [CSRF tokens](/security/csrf) and [signed URLs](/routing/named-routes). Empty: CSRF verification fails closed, and signing a URL throws. |
| `APP_URL` | empty | `UrlGenerator` (via `url()` / the `CoreContainer` binding) | Base URL for absolute URLs (`route(..., absolute: true)`, signed links). |
| `APP_DEBUG` | `false` | `ErrorStage`, `DebugPageRenderer` | Shows the HTML debug page (trace and source excerpt) and real messages for critical errors. Never enable in production. See [Error pipeline](/errors/error-pipeline). |
| `ERROR_STATUS_LEGACY` | `false` | `ErrorStage`, `ErrorClassifier` | Restores the error mapping of 1.17 and earlier: the kernel `DomainException`, `OptimisticLockException` and `LockTimeoutException` answer 500 again, and the two lock exceptions are critical again. A migration aid for clients or alert rules that depend on the old codes; it will be removed in 2.0. See [Exceptions](/errors/exceptions#exception-reference). |
| `APP_ENV` | none | `DebugPageRenderer`; the `.env` loader | Shown on the debug page. Also selects `.env.{APP_ENV}` in the [cascade](/guide/configuration#the-env-cascade). |

### Boot

| Variable | Default | Read by | Effect |
|---|---|---|---|
| `BOOT_CACHE` | `false` | `BootStamp` | Skips manifest recompilation when nothing the compile read has changed. Turn it on for PHP-FPM in production, and clear `var/cache/manifests/` on deploy. See [Boot pipeline](/architecture/boot-pipeline). |

### Routing

All are read once, when the HTTP pipeline is built.

| Variable | Default | Effect |
|---|---|---|
| `ROUTE_HEAD_FALLBACK` | `true` | A `HEAD` with no route of its own is served by the `GET` route, body stripped. |
| `ROUTE_METHOD_NOT_ALLOWED` | `false` | Answer `405` with an `Allow` header on a method mismatch instead of `404`. Off by default, because a 405 confirms that a path exists. |
| `ROUTE_TRAILING_SLASH` | `strict` | `strict`: `/users/` and `/users` differ. `ignore`: match either. `redirect`: 301 to the canonical form. Any other value means `strict`. |
| `ROUTE_STRICT_FILTERS` | `true` | A route naming an unregistered filter alias fails at pipeline build rather than when the route is requested. |
| `ROUTE_VERIFY_HANDLERS` | `false` | At boot, check that every handler class and public method exists. Meant for development and CI; it autoloads every controller. |

See [Declaring routes](/routing/basics).

### Workers

| Variable | Default | Effect |
|---|---|---|
| `JOB_SIGNING_SECRET` | empty (verification off) | HMAC secret the worker uses to verify every dequeued payload. `Kernel::withWorkerSecret()` takes precedence. Turn it on producer-first: an unsigned payload is rejected and dead-lettered. See [Workers & jobs](/background/workers-and-jobs). |

## Generated project bootstrap

These are read by the entry points and bootstrap files a new project gets (`templates/`, `projects/Bootstrap/`), not by `src/Kernel`.

| Variable | Default | Effect |
|---|---|---|
| `APP_ENV` | `production` | Read by the generated bootstrap. Outside `local` and `testing`, an empty `APP_KEY` **refuses to boot**. `app/public/index.php` also never shows debug output when this is `production`, even with `APP_DEBUG` on. |
| `APP_KEY_PREVIOUS` | empty | The previous key, kept during key rotation: the scaffolded `EncryptionPort` adapter receives both keys, so data encrypted with the old key still decrypts. |
| `APP_TIMEZONE` | `UTC` | Passed to `date_default_timezone_set()` at boot. Affects scheduled tasks that declare no `timezone`. |
| `HASH_BCRYPT_COST` | `12` | bcrypt cost for the scaffolded `HashingPort` adapter. |
| `DB_POOL_ENABLED` | `false` | CLI only: bind a connection pool (Database plugin) for long-running processes. |
| `DB_ENABLE_QUERY_LOG` | `false` | With the pool enabled, log each query. |
| `TRUSTED_PROXIES` | empty (trust none) | Read by `app/public/index.php` and `app/swoole/index.php`: comma-separated proxy IPs/CIDRs (preferred), `PRIVATE_SUBNETS` (wider than RFC 1918: includes CGNAT `100.64.0.0/10`), or (FPM only) `REMOTE_ADDR`. `X-Forwarded-For/-Proto/-Port` from these proxies are honoured, so `Request::ip()` and `isSecure()` reflect the real client. See [Request](/http/request#request-properties-as-values). |
| `WORKER_QUEUE` | `default` | Queue `app/worker/run.php` consumes. |
| `WORKER_MAX_ITERATIONS` | `0` (forever) | Jobs a worker processes before exiting, for supervisor-driven restarts. |
| `ENV_CACHE` | `false` | **Real env only.** Compiles the resolved `.env` cascade to `var/cache/env.*.php`. See [Configuration](/guide/configuration#compiled-env-cache-env-cache). |
| `APP_DOMAIN` | none | **Real env only** (read before the cascade loads). On the CLI, the host used to pick the domain tier of the `.env` cascade (same as `--domain`). |
| `HKM_PROJECT` | `admin` | **Real env only.** The project to boot when no host resolved one (CLI, workers, an unmatched host). |
| `HKM_USERDATA_DIR` | `{root}/projects` | **Real env only** (domain resolution runs before `.env` loads). Where the machine-wide project registry (`projects.json`) is looked up. |
| `SESSION_COOKIE` | `hkm_session` | In the scaffolded bootstrap, the cookie the CSRF layer's `bindCookie` pins tokens to. It must be an unencrypted cookie that does not rotate. See [CSRF](/security/csrf). |
| `HKM_GLOBAL_AUTOLOAD` | none | **Real env only.** Explicit path to the kernel's `vendor/autoload.php` for a project using a globally installed kernel. |
| `PSP_GLOBAL_AUTOLOAD` | none | **Real env only.** Pre-rename spelling of `HKM_GLOBAL_AUTOLOAD`, still honored. |
| `HKM_KERNEL_HOME` | none | **Real env only.** Kernel install directory; its `vendor/autoload.php` is a candidate for the global autoload. |
| `COMPOSER_HOME` | none | **Real env only.** Its `vendor/autoload.php` is a candidate for the global autoload. `HOME`, `APPDATA` and `USERPROFILE` supply the platform defaults after it. |

The global-autoload lookup order is described in [Installation](/guide/installation).

### OpenSwoole entry point

Read by the generated `app/swoole/index.php`:

| Variable | Default | Effect |
|---|---|---|
| `SWOOLE_HOST` | `127.0.0.1` | Bind address. |
| `SWOOLE_PORT` | `9502` | Bind port. |
| `HKM_WORKERS` | CPU count (else 4) | `worker_num`. |
| `HKM_ENV` | `production` | Environment name passed to the server. |
| `SWOOLE_MAX_REQUEST` | `0` (never) | Restart a worker after this many requests (`max_request`). |
| `SWOOLE_DAEMONIZE` | `false` | Run the server as a daemon. |
| `SWOOLE_COROUTINE` | `false` | Enable coroutine hooks. |

## Packages

| Variable | Package | Effect |
|---|---|---|
| `NO_COLOR` | php-io-cli | **Real env only.** When set to any value, disables ANSI color ([no-color.org](https://no-color.org)). Checked first. |
| `FORCE_COLOR` | php-io-cli | **Real env only.** When set, forces color on, for example in CI logs. |
| `GROUND_WORKSPACE_DIR` | ground | Parent directory for test workspaces (default: the system temp dir). |
| `GROUND_KEEP_WORKSPACE` | ground | Keep the workspace after a run, for inspection. |
| `GROUND_KEEP_DATABASE` | ground | **Real env only.** Keep the test database after a failed migration, for inspection. |

LetMigrate reads no environment variables itself. Its connection settings are passed in by whatever constructs it.

## Your own variables

Read your own variables with `env()`, and declare each one a module reads in that module's `module.json` `config[]`, or the boot fails:

```php
$apiKey  = env('EXTERNAL_API_KEY');
$timeout = (int) env('EXTERNAL_API_TIMEOUT', 30);
```

For structured settings, prefer a `config/*.php` file read through `config()`. See [Configuration](/guide/configuration).

## Source

- [`src/Kernel/Support/helpers.php`](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Support/helpers.php) (`env()`)
- [`src/Kernel/Pipelines/Http/HttpPipeline.php`](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Pipelines/Http/HttpPipeline.php) (routing flags)
- [`src/Kernel/Pipelines/Http/Stages/ErrorStage.php`](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Pipelines/Http/Stages/ErrorStage.php) (`APP_DEBUG`)
- [`src/Kernel/Boot/BootStamp.php`](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Boot/BootStamp.php) (`BOOT_CACHE`)
- [`src/Kernel/Kernel.php`](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Kernel.php) (`JOB_SIGNING_SECRET`, `APP_URL`)
- [`projects/Bootstrap/Environment/LoadEnvironment.php`](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/projects/Bootstrap/Environment/LoadEnvironment.php) (`.env` cascade, `ENV_CACHE`)
- [`templates/app/swoole/index.php`](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/templates/app/swoole/index.php)
- [`src/System/GlobalKernelProjectScaffolder.php`](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/System/GlobalKernelProjectScaffolder.php) (global autoload)
