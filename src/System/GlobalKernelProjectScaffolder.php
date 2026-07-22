<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\System;

final class GlobalKernelProjectScaffolder
{
    /**
     * @param list<string> $domains Domains this project answers to (the project
     *                              defines them; they are mirrored into proj.json
     *                              and, on registration, into the kernel registry).
     */
    public function create(string $targetDir, string $projectName = 'admin', array $domains = []): void
    {
        $root = rtrim($targetDir, DIRECTORY_SEPARATOR);

        if (is_dir($root) && $this->directoryNotEmpty($root)) {
            throw new \RuntimeException("Target directory is not empty: {$root}");
        }

        $this->mkdir($root);

        // FLAT layout: the target directory IS the project. There is NO nested
        // projects/<name>/ tree — config/database/var/userdata live at the root.
        $dirs = [
            'app/bootstrap',
            'app/public',
            'app/cli',
            'app/Infrastructure',
            'src/Support',
            'config/environments',
            'database/migrations',
            'database/seeders',
            'database/factories',
            'var/cache/manifests',
            'var/logs',
            'var/tmp',
            'var/locks',
            'userdata',
        ];

        foreach ($dirs as $dir) {
            $this->mkdir($root . DIRECTORY_SEPARATOR . $dir);
        }

        $this->write($root . '/.gitignore', $this->gitignore());
        $this->write($root . '/README.md', $this->readme($projectName));
        $this->write($root . '/composer.json', $this->composerJson($projectName));
        $this->write($root . '/proj.json', $this->projJson($projectName, $domains));
        $this->write($root . '/src/README.md', $this->srcReadme($projectName));
        $this->write($root . '/src/Support/Clock.php', $this->srcSampleClass($projectName));
        $this->write($root . '/app/bootstrap/kernel-autoload.php', $this->kernelAutoloadFile());
        $this->write($root . '/app/bootstrap/base.php', $this->baseBootstrap());
        $this->write($root . '/app/bootstrap/app.php', $this->projectBootstrap($projectName));
        $this->write($root . '/app/public/index.php', $this->httpEntry($projectName));
        $this->write($root . '/app/cli/run.php', $this->cliEntry($projectName));
        $this->write($root . '/var/cache/manifests/.gitkeep', "\n");
        $this->write($root . '/var/logs/.gitkeep', "\n");
        $this->write($root . '/var/tmp/.gitkeep', "\n");
        $this->write($root . '/var/locks/.gitkeep', "\n");
        $this->write($root . '/userdata/.gitkeep', "\n");
        $this->write($root . '/database/migrations/.gitkeep', "\n");
        $this->write($root . '/database/seeders/.gitkeep', "\n");
        $this->write($root . '/database/factories/.gitkeep', "\n");
        $this->write($root . '/config/let-migrate.php', $this->letMigrateConfig($projectName));
        $this->write($root . '/config/environments/local.php', $this->envConfig('local', $projectName));
        $this->write($root . '/config/environments/staging.php', $this->envConfig('staging', $projectName));
        $this->write($root . '/config/environments/production.php', $this->envConfig('production', $projectName));
        $this->write($root . '/config/environments/testing.php', $this->envConfig('testing', $projectName));
        $this->write($root . '/app/Infrastructure/InMemoryCache.php', $this->inMemoryCacheAdapter());
        $this->write($root . '/app/Infrastructure/NullDatabase.php', $this->nullDatabaseAdapter());
    }

    private function directoryNotEmpty(string $dir): bool
    {
        $entries = array_diff(scandir($dir) ?: [], ['.', '..']);
        return $entries !== [];
    }

    private function mkdir(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
            throw new \RuntimeException("Failed to create directory: {$path}");
        }
    }

    private function write(string $path, string $content): void
    {
        $dir = dirname($path);
        $this->mkdir($dir);
        if (file_put_contents($path, $content) === false) {
            throw new \RuntimeException("Failed to write file: {$path}");
        }
    }

    private function gitignore(): string
    {
        return <<<'TXT'
# Composer
/vendor/
composer.lock

# Runtime
var/cache/
var/logs/
var/tmp/
var/locks/
var/sessions/
!**/.gitkeep

# User data
userdata/*
!userdata/.gitkeep

# Local databases
database/*.sqlite
database/*.db

# OS/IDE
.DS_Store
Thumbs.db
.vscode/
.idea/
TXT;
    }

    private function readme(string $projectName): string
    {
        $ns = $this->studly($projectName);

        return <<<MD
# PSP Project — {$projectName}

Scaffolded to run against a **globally installed** HKM kernel (hybrid model):
the kernel is shared system-wide, while THIS project owns its plugins and its
project-only code locally.

## Quick start

1. Install the kernel globally (once per machine):
   - `composer global require alfacode-team/php-service-platform`
2. Install this project's local dependencies (plugins):
   - `composer install`
3. Run the CLI:
   - `php app/cli/run.php list`
4. Serve HTTP with the built-in server:
   - `php -S 127.0.0.1:8080 -t app/public`

## Layout

This directory **is** the project (flat layout — there is no nested
`projects/<name>/` folder).

| Path        | Role                                                         |
|-------------|--------------------------------------------------------------|
| `app/`      | Entry points, bootstrap, infrastructure adapters             |
| `src/` (`{$ns}\\`) | Project-only logic — services, listeners, VOs         |
| `config/`   | let-migrate + per-environment config                         |
| `database/` | migrations, seeders, factories, local sqlite                 |
| `var/`      | runtime — cache, logs, tmp, locks                            |
| `userdata/` | persisted user data (uploads/exports)                        |
| `vendor/`   | local plugins (kernel comes from the global install)         |

## Adding plugins (composed by THIS project)

```
composer require <vendor>/<plugin>
```

Then register the plugin's Provider in
`app/bootstrap/app.php` under `->withModules([...])`.

> Plugins must NOT hard-require the kernel package in their own composer.json,
> or Composer will pull a second kernel copy into this project's `vendor/`.
> They couple to the kernel only through its published interfaces, which the
> global install provides at runtime.

## Kernel resolution

`app/bootstrap/kernel-autoload.php` loads the local `vendor/` first (plugins +
project `src/`), then the global kernel. Override the global path with
`PSP_GLOBAL_AUTOLOAD=/abs/path/to/vendor/autoload.php`.
MD;
    }

    /**
     * Convert a project name into a StudlyCase PSR-4 namespace root.
     * e.g. "my-blog" / "my_blog" → "MyBlog". Falls back to "App" if empty.
     */
    private function studly(string $name): string
    {
        $parts = preg_split('/[^a-zA-Z0-9]+/', $name) ?: [];
        $studly = implode('', array_map(static fn(string $p): string => ucfirst(strtolower($p)), array_filter($parts)));
        if ($studly === '' || ctype_digit($studly[0])) {
            $studly = 'App' . $studly;
        }
        return $studly;
    }

    /**
     * Project composer.json. HYBRID MODEL: the project owns its plugins (and its
     * src/) locally, but does NOT require the kernel package — the kernel is
     * resolved from the GLOBAL install by app/bootstrap/kernel-autoload.php.
     *
     * Add plugins with:  composer require <vendor>/<plugin>
     * (Plugins must NOT hard-require the kernel package, or Composer will pull a
     * second kernel copy into this project's vendor/.)
     */
    private function composerJson(string $projectName): string
    {
        $ns = $this->studly($projectName);

        return <<<JSON
{
    "name": "local/{$projectName}",
    "description": "PSP project '{$projectName}' — runs against a globally installed HKM kernel.",
    "type": "project",
    "require": {
        "php": ">=8.2"
    },
    "autoload": {
        "psr-4": {
            "App\\\\": "app/",
            "{$ns}\\\\": "src/"
        }
    },
    "config": {
        "optimize-autoloader": true,
        "sort-packages": true
    },
    "minimum-stability": "dev",
    "prefer-stable": true
}

JSON;
    }

    private function srcReadme(string $projectName): string
    {
        $ns = $this->studly($projectName);

        return <<<MD
# src — project-only logic

Code here belongs to the **{$projectName}** project and is neither a kernel
concern nor a reusable plugin. Use it for project-specific services, listeners,
value objects, and wiring glue.

## Namespace

```
{$ns}\\  →  src/
```

So `src/Support/Clock.php` is `{$ns}\\Support\\Clock`.

## Where code goes

| Where        | Use for                                                         |
|--------------|-----------------------------------------------------------------|
| (global kernel) | Framework internals — never project/business logic           |
| plugins      | Reusable business modules — `composer require` them            |
| `src/`       | Logic specific to THIS project only                            |

Routes live ONLY in a plugin's `module.json` — never here. Wire anything in
`src/` through `app/bootstrap/app.php`.
MD;
    }

    private function srcSampleClass(string $projectName): string
    {
        $ns = $this->studly($projectName);

        return <<<PHP
<?php

declare(strict_types=1);

namespace {$ns}\\Support;

/**
 * Example project-only class. Autoloads as {$ns}\\Support\\Clock via the
 * "{$ns}\\\\": "src/" PSR-4 mapping in composer.json. Replace or delete it.
 */
final readonly class Clock
{
    public function now(): \\DateTimeImmutable
    {
        return new \\DateTimeImmutable();
    }
}
PHP;
    }

    private function kernelAutoloadFile(): string
    {
        return <<<'PHP'
<?php
declare(strict_types=1);

/**
 * Load the globally installed kernel package autoloader.
 *
 * Resolution order:
 *  1. PSP_GLOBAL_AUTOLOAD env var (explicit path to vendor/autoload.php)
 *  2. Local project vendor/autoload.php (if project also has local deps)
 *  3. COMPOSER_HOME/vendor/autoload.php
 *  4. Linux/macOS defaults (~/.config/composer, ~/.composer)
 *  5. Windows defaults (%APPDATA%/Composer, %USERPROFILE%/.composer)
 */

if (!function_exists('psp_require_kernel_autoload')) {
    function psp_require_kernel_autoload(): void
    {
        // HYBRID MODEL: the kernel is installed GLOBALLY, but this project owns
        // its plugins (and its src/) via a LOCAL composer.json + vendor/. Always
        // load the local vendor FIRST (so plugins + project PSR-4 register), then
        // fall through to the global kernel autoload below. Loading the local
        // vendor never short-circuits the global kernel lookup unless the project
        // also vendored the kernel itself.
        $localVendor = dirname(__DIR__, 2) . '/vendor/autoload.php';
        if (is_file($localVendor)) {
            require_once $localVendor;
        }

        if (class_exists(\AlfacodeTeam\PhpServicePlatform\Kernel\Kernel::class)) {
            return; // local vendor already provided the kernel — nothing global to do
        }

        $candidates = [];

        $explicit = getenv('PSP_GLOBAL_AUTOLOAD');
        if (is_string($explicit) && $explicit !== '') {
            $candidates[] = $explicit;
        }

        $composerHome = getenv('COMPOSER_HOME');
        if (is_string($composerHome) && $composerHome !== '') {
            $candidates[] = rtrim($composerHome, '/\\') . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
        }

        $home = getenv('HOME');
        if (is_string($home) && $home !== '') {
            $home = rtrim($home, '/\\');
            $candidates[] = $home . '/.config/composer/vendor/autoload.php';
            $candidates[] = $home . '/.composer/vendor/autoload.php';
        }

        $appData = getenv('APPDATA');
        if (is_string($appData) && $appData !== '') {
            $candidates[] = rtrim($appData, '/\\') . DIRECTORY_SEPARATOR . 'Composer' . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
        }

        $userProfile = getenv('USERPROFILE');
        if (is_string($userProfile) && $userProfile !== '') {
            $candidates[] = rtrim($userProfile, '/\\') . DIRECTORY_SEPARATOR . '.composer' . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
        }

        foreach ($candidates as $autoload) {
            if (is_string($autoload) && is_file($autoload)) {
                require_once $autoload;
                if (class_exists(\AlfacodeTeam\PhpServicePlatform\Kernel\Kernel::class)) {
                    return;
                }
            }
        }

        $msg = "[HKM] Could not load the global kernel autoload.\n"
            . "Install globally: composer global require alfacode-team/php-service-platform\n"
            . "Or set PSP_GLOBAL_AUTOLOAD=/absolute/path/to/vendor/autoload.php\n";

        // STDERR only exists under the CLI SAPI; the built-in/web server has no
        // such constant, so guard it to avoid masking the real error.
        if (\PHP_SAPI === 'cli' || \PHP_SAPI === 'phpdbg') {
            fwrite(\defined('STDERR') ? STDERR : fopen('php://stderr', 'w'), $msg);
        } else {
            if (!headers_sent()) {
                http_response_code(500);
                header('Content-Type: text/plain; charset=utf-8');
            }
            echo $msg;
        }
        exit(1);
    }
}

if (!function_exists('psp_register_project_autoload')) {
    /**
     * Minimal local autoloader for scaffolded projects that do not have
     * their own composer.json/vendor yet. Currently maps App\\* to /app/*.
     */
    function psp_register_project_autoload(): void
    {
        spl_autoload_register(static function (string $class): void {
            $prefix = 'App\\';
            if (!str_starts_with($class, $prefix)) {
                return;
            }

            $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
            $path = dirname(__DIR__, 2) . '/app/' . $relative . '.php';
            if (is_file($path)) {
                require_once $path;
            }
        });
    }
}
PHP;
    }

    private function baseBootstrap(): string
    {
        return <<<'PHP'
<?php
declare(strict_types=1);

require_once __DIR__ . '/kernel-autoload.php';
psp_require_kernel_autoload();
psp_register_project_autoload();

use AlfacodeTeam\PhpServicePlatform\Kernel\Kernel;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\CachePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Layers\CsrfTokenLayer;
use App\Infrastructure\InMemoryCache;
use App\Infrastructure\NullDatabase;

return Kernel::configure()
    ->withBasePath(dirname(__DIR__, 2))
    ->withPorts([
        DatabasePort::class => new NullDatabase(),
        CachePort::class => new InMemoryCache(),
    ])
    ->withSecurity([
        new CsrfTokenLayer(
            bindCookie:  env('SESSION_COOKIE') ?: 'hkm_session',
            exemptPaths: ['/api'],
        ),
    ]);
PHP;
    }

    private function projectBootstrap(string $projectName): string
    {
        return <<<'PHP'
<?php
declare(strict_types=1);

/**
 * app/bootstrap/app.php — THE project bootstrap.
 *
 * This directory IS the project (flat layout — no nested projects/<name>/).
 * base.php returns the unbuilt kernel builder; here we add this project's
 * modules and build. projectPath == basePath == the project root, so
 * Paths::var()/logs()/config()/database() all resolve at the root.
 *
 * Add plugins with `composer require <vendor>/<plugin>` then list their
 * Provider in withModules([...]) below.
 */

use AlfacodeTeam\PhpServicePlatform\Kernel\Kernel;

/** @var Kernel $builder */
$builder = require __DIR__ . '/base.php';

return $builder
    ->withProjectPath(dirname(__DIR__, 2))
    ->withModules([])
    ->build();
PHP;
    }

    private function httpEntry(string $projectName): string
    {
        return <<<'PHP'
<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/kernel-autoload.php';
psp_require_kernel_autoload();

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use App\Bootstrap\EntryHelpers;
use App\Bootstrap\Environment\ErrorGuard;
use App\Bootstrap\Environment\LoadEnvironment;

// This directory IS the project (flat layout). projectRoot == the project root.
$projectRoot = dirname(__DIR__, 2);

// 1. Resolve the domain (face: admin/api/project) from the Host header.
$domain = EntryHelpers::resolveDomain($projectRoot, $_SERVER['HTTP_HOST'] ?? null);

// 2. Load the .env cascade BEFORE the kernel builder reads any configuration.
LoadEnvironment::load($projectRoot, $domain);

// 3. Install the pre-kernel error net (writes to the same log the kernel uses).
ErrorGuard::install($projectRoot . '/var/logs/errors.log');

/** @var \AlfacodeTeam\PhpServicePlatform\Kernel\Kernel $kernel */
$kernel = require __DIR__ . '/../bootstrap/app.php';

try {
    $request = Request::capture();
    if ($domain !== null) {
        $request = $request->withAttribute('domain', $domain);
    }
    $kernel->http()->handle($request)->send();
} catch (\Throwable $e) {
    $debug = filter_var($_ENV['APP_DEBUG'] ?? getenv('APP_DEBUG') ?: 'false', FILTER_VALIDATE_BOOL);
    Response::json([
        'error' => [
            'code'    => 'kernel.unhandled',
            'message' => $debug ? $e->getMessage() : 'Internal Server Error',
        ],
    ], 500)->send();
}
PHP;
    }

    private function cliEntry(string $projectName): string
    {
        return <<<'PHP'
<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/kernel-autoload.php';
psp_require_kernel_autoload();

use App\Bootstrap\Environment\ErrorGuard;
use App\Bootstrap\Environment\LoadEnvironment;

$projectRoot = dirname(__DIR__, 2);

// Load .env (honours --env / --domain CLI flags) before the kernel builder runs.
LoadEnvironment::load($projectRoot, null, $argv);
ErrorGuard::install($projectRoot . '/var/logs/errors.log');

/** @var \AlfacodeTeam\PhpServicePlatform\Kernel\Kernel $kernel */
$kernel = require __DIR__ . '/../bootstrap/app.php';
exit($kernel->cli()->run($argv));
PHP;
    }

    /**
     * @param list<string> $domains
     */
    private function projJson(string $projectName, array $domains = []): string
    {
        return $this->jsonEncode([
            'name'    => $projectName,
            'version' => '1.0.0',
            'domains' => array_values($domains),
            'features' => [],
        ]) . "\n";
    }

    /**
     * Register (or update) this project in the kernel registry so the kernel's
     * DomainResolver can map a Host header to it. Writes:
     *   - <kernelProjectsDir>/projects.json : name => {name, version, path, domains}
     *   - <kernelProjectsDir>/platform.json : ensures the file exists; merges any
     *                                         admin/api subdomain faces given.
     *
     * @param list<string> $domains
     * @param list<string> $adminSubs Subdomain labels that mean the "admin" face
     * @param list<string> $apiSubs   Subdomain labels that mean the "api" face
     * @return array{projects: string, platform: string} Absolute paths written
     */
    public function registerInKernel(
        string $kernelProjectsDir,
        string $projectName,
        string $projectPath,
        array $domains = [],
        array $adminSubs = [],
        array $apiSubs = [],
    ): array {
        $dir = rtrim($kernelProjectsDir, DIRECTORY_SEPARATOR);
        $this->mkdir($dir);

        $projectsFile = $dir . '/projects.json';
        $platformFile = $dir . '/platform.json';

        // ---- projects.json -------------------------------------------------
        $projects = is_file($projectsFile) ? $this->readJsonFile($projectsFile) : [];
        $projects[$projectName] = [
            'name'    => $projectName,
            'version' => '1.0.0',
            'path'    => rtrim($projectPath, DIRECTORY_SEPARATOR),
            'domains' => array_values($domains),
        ];
        $this->write($projectsFile, $this->jsonEncode($projects) . "\n");

        // ---- platform.json (global subdomain → face registry) --------------
        $platform = is_file($platformFile) ? $this->readJsonFile($platformFile) : [];
        if (!isset($platform['subdomains']) || !is_array($platform['subdomains'])) {
            $platform['subdomains'] = ['admin' => ['app', 'admin'], 'api' => ['api']];
        }
        $platform['subdomains']['admin'] = $this->mergeUnique(
            $platform['subdomains']['admin'] ?? [],
            $adminSubs,
        );
        $platform['subdomains']['api'] = $this->mergeUnique(
            $platform['subdomains']['api'] ?? [],
            $apiSubs,
        );
        $this->write($platformFile, $this->jsonEncode($platform) . "\n");

        return ['projects' => $projectsFile, 'platform' => $platformFile];
    }

    /**
     * @param array<int|string, mixed> $a
     * @param array<int|string, mixed> $b
     * @return list<string>
     */
    private function mergeUnique(array $a, array $b): array
    {
        $out = [];
        foreach ([...array_values($a), ...array_values($b)] as $v) {
            if (is_string($v) && $v !== '' && !in_array($v, $out, true)) {
                $out[] = $v;
            }
        }
        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function readJsonFile(string $file): array
    {
        $raw = @file_get_contents($file);
        if ($raw === false || trim($raw) === '') {
            return [];
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    /**
     * @param mixed $data
     */
    private function jsonEncode(mixed $data): string
    {
        return (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function letMigrateConfig(string $projectName): string
    {
        // config/let-migrate.php → root is one level up.
        return <<<'PHP'
<?php

declare(strict_types=1);

$root = dirname(__DIR__, 1);

return [
    'default' => 'default',
    'connections' => [
        'default' => [
            'driver' => 'sqlite',
            'database' => $root . '/database/app.sqlite',
            'host' => 'localhost',
            'port' => 0,
            'username' => '',
            'password' => '',
        ],
    ],
    'paths' => [
        $root . '/database/migrations',
    ],
    'seeders_path' => $root . '/database/seeders',
    'factories_path' => $root . '/database/factories',
    'tracking_table' => 'let_migrations',
    'pretend' => false,
    'transactional' => true,
];
PHP;
    }

    private function envConfig(string $env, string $projectName): string
    {
        $transactional = $env === 'production' ? 'true' : 'false';
        // config/environments/<env>.php → root is two levels up.
        $template = <<<'PHP'
<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

return [
    'default' => 'default',
    'connections' => [
        'default' => [
            'driver' => 'sqlite',
            'database' => $root . '/database/app.sqlite',
            'host' => 'localhost',
            'port' => 0,
            'username' => '',
            'password' => '',
        ],
    ],
    'paths' => [
        $root . '/database/migrations',
    ],
    'seeders_path' => $root . '/database/seeders',
    'factories_path' => $root . '/database/factories',
    'tracking_table' => 'let_migrations',
    'pretend' => false,
    'transactional' => __TRANSACTIONAL__,
];
PHP;

        return str_replace('__TRANSACTIONAL__', $transactional, $template);
    }

    private function inMemoryCacheAdapter(): string
    {
        return <<<'PHP'
<?php
declare(strict_types=1);

namespace App\Infrastructure;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\CachePort;

final class InMemoryCache implements CachePort
{
    /** @var array<string, mixed> */
    private array $items = [];

    public function get(string $key): mixed
    {
        return $this->items[$key] ?? null;
    }

    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        $this->items[$key] = $value;
        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->items[$key]);
        return true;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->items);
    }

    public function remember(string $key, int $ttl, callable $callback): mixed
    {
        if (!$this->has($key)) {
            $this->set($key, $callback(), $ttl);
        }
        return $this->get($key);
    }

    public function increment(string $key, int $by = 1): int
    {
        $current = (int) ($this->items[$key] ?? 0);
        $current += $by;
        $this->items[$key] = $current;
        return $current;
    }

    public function deletePattern(string $pattern): int
    {
        $regex = '/^' . str_replace(['*', '?'], ['.*', '.'], preg_quote($pattern, '/')) . '$/';
        $count = 0;
        foreach (array_keys($this->items) as $key) {
            if (preg_match($regex, $key) === 1) {
                unset($this->items[$key]);
                $count++;
            }
        }
        return $count;
    }

    public function flush(): bool
    {
        $this->items = [];
        return true;
    }
}
PHP;
    }

    private function nullDatabaseAdapter(): string
    {
        return <<<'PHP'
<?php
declare(strict_types=1);

namespace App\Infrastructure;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;

/**
 * Minimal bootstrap adapter so a fresh scaffold can boot the kernel.
 * Replace with your real adapter (MySQL/Postgres/etc) for production.
 */
final class NullDatabase implements DatabasePort
{
    public function query(string $sql, array $params = []): array
    {
        return [];
    }

    public function queryOne(string $sql, array $params = []): ?array
    {
        return null;
    }

    public function execute(string $sql, array $params = []): int
    {
        return 0;
    }

    public function upsert(string $table, array $values, array $conflictColumns, ?array $updateColumns = null): int
    {
        return 0;
    }

    public function lastInsertId(?string $sequence = null): string
    {
        return '0';
    }

    public function beginTransaction(): void
    {
    }

    public function commit(): void
    {
    }

    public function rollback(): void
    {
    }

    public function inTransaction(): bool
    {
        return false;
    }
}
PHP;
    }
}
