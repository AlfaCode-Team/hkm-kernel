<?php

declare(strict_types=1);

/**
 * =============================================================================
 *  PROJECT BOOTSTRAP  (app/bootstrap/app.php)
 * =============================================================================
 *
 * This is THE single place your whole application is assembled. Every entry
 * point of the project — the web front controller (app/public/index.php) and
 * the CLI runner (app/cli/run.php) — `require`s this file and receives back a
 * fully-configured, ready-to-run Kernel object.
 *
 * It returns the Kernel (it does NOT run it): the calling entry point decides
 * which surface to drive — `$kernel->http()->handle(...)` for web,
 * `$kernel->cli()->run(...)` for the terminal. Keeping wiring here and execution
 * in the entry points means all surfaces share one identical configuration.
 *
 * -----------------------------------------------------------------------------
 *  FLAT LAYOUT
 * -----------------------------------------------------------------------------
 * This project uses the flat layout: the scaffolded directory IS the project.
 * There is no nested projects/<name>/ folder, so the base path and the project
 * path are the SAME directory — the project root. `dirname(__DIR__, 2)` walks up
 * two levels (bootstrap → app → root) to find it.
 *
 * -----------------------------------------------------------------------------
 *  BOOT ORDER (top to bottom in this file — order matters)
 * -----------------------------------------------------------------------------
 *   1. Autoloaders   — make the kernel + plugins + your code loadable.
 *   2. Domain        — map the incoming Host header to a project "face".
 *   3. Environment   — load the .env cascade BEFORE any config is read.
 *   4. Error net     — install the pre-kernel safety net (catches early fatals).
 *   5. APP_KEY guard — refuse to boot outside local/testing without a real key.
 *   6. Ports         — declare lazy infrastructure factories (DB, cache, ...).
 *   7. Kernel build  — configure modules + security and compile manifests.
 *
 * `build()` is compile-only: it validates config and compiles manifests but does
 * NOT construct pipelines or wire modules. That happens lazily on the first
 * http()/cli() call in the entry point — so a CLI process never pays for HTTP
 * wiring and vice-versa.
 * =============================================================================
 */

// -----------------------------------------------------------------------------
// STEP 1 — AUTOLOADERS
// Load the kernel autoload helper (defined in kernel-autoload.php), then run it
// to register the framework kernel, the Plugins\ namespace, and this project's
// PSR-4 roots. The guard keeps this safe even when an entry point already loaded
// the helper.
// -----------------------------------------------------------------------------
if (!function_exists('psp_require_kernel_autoload') || !function_exists('psp_kernel_home')) {
    require_once __DIR__ . '/kernel-autoload.php';
}
psp_require_kernel_autoload();

use AlfacodeTeam\PhpServicePlatform\Kernel\Kernel;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\CachePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\QueuePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\EncryptionPort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\HashingPort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Layers\CsrfTokenLayer;

use Project\Bootstrap\EntryHelpers;
use Project\Bootstrap\Environment\ErrorGuard;
use Project\Bootstrap\Environment\LoadEnvironment;
use Project\Infrastructure\FileQueue;
use Project\Infrastructure\InMemoryCache;
use Project\Infrastructure\PdoDatabase;

// Plugins — port adapters / infrastructure.
use Plugins\Crypto\Infrastructure\AesEncrypter;
use Plugins\Crypto\Infrastructure\PasswordHasher;
use Plugins\Database\Infrastructure\Drivers\DatabaseConfigurationFactory;
use Plugins\Database\Infrastructure\Persistence\MultiDriverDatabaseAdapter;
use Plugins\Database\Infrastructure\Pool\ConnectionPool;
use Plugins\Database\Infrastructure\Pool\PoolConfiguration;



// Plugins — module providers (registered into the kernel below).
use Plugins\Crypto\Provider as CryptoProvider;
use Plugins\I18n\Provider as I18nProvider;
use Plugins\Database\Provider as DatabaseProvider;
use Plugins\Commands\Provider as CommandsProvider;
use Plugins\Storage\Provider as StorageProvider;
use Plugins\HttpClient\Provider as HttpClientProvider;
use Plugins\Session\Provider as SessionProvider;
use Plugins\Cookie\Provider as CookieProvider;
use Plugins\RedisCache\Provider as RedisCacheProvider;
use Plugins\SiteSEO\Application\Listeners\EnqueueIndexNowListener;
use Plugins\SiteSEO\Provider as SiteSeoModule;
use Plugins\View\Provider as ViewModule;
use Plugins\SecurityFilters\Provider as SecurityFiltersModule;


// Flat layout: this directory's grandparent is the project root.
$projectRoot = dirname(__DIR__, 2);

// -----------------------------------------------------------------------------
// STEP 2 — DOMAIN RESOLUTION
// Translate the request's Host header into a DomainContext (project face:
// admin / api / project / public + any features). This stays in the project
// layer so the kernel never needs to know about hosts. It is null under CLI and
// workers (no Host header) — that is expected and handled downstream.
// -----------------------------------------------------------------------------
$domain = EntryHelpers::resolveDomain($projectRoot, $_SERVER['HTTP_HOST'] ?? null);

// -----------------------------------------------------------------------------
// STEP 3 — ENVIRONMENT
// Load the .env cascade BEFORE anything reads configuration. Values already in
// the real process environment always win, so true OS/server config is never
// clobbered. (Note: .env values are injected into $_ENV/$_SERVER, NOT putenv() —
// always read config via the env() helper below, never getenv().)
// -----------------------------------------------------------------------------
// $_SERVER['argv'] is present under the CLI SAPI (null on web), so passing it
// here lets `--env=...` / `--domain=...` flags select the .env tier for console
// commands — without the CLI entry needing to load the environment itself.
LoadEnvironment::load($projectRoot, $domain, $_SERVER['argv'] ?? null);

// -----------------------------------------------------------------------------
// STEP 4 — PRE-KERNEL ERROR NET
// The outer safety net. It catches throws and PHP fatals that happen BEFORE the
// kernel's own error pipeline is live (e.g. the APP_KEY guard just below, parse
// errors, out-of-memory). It writes to the same log the kernel's FileNotifier
// uses, so all errors land in one place: var/logs/errors.log.
// -----------------------------------------------------------------------------
ErrorGuard::install($projectRoot . '/var/logs/errors.log');

// Canonical config reader. $_ENV is the source of truth (LoadEnvironment does
// not call putenv(), so getenv() will NOT see .env values). This wraps the
// global env() helper and normalises the result to a nullable string.
$env = static fn(string $key, ?string $default = null): ?string =>
    (($v = env($key)) !== null ? (string) $v : $default);

// -----------------------------------------------------------------------------
// STEP 5 — ENCRYPTION KEY (FAIL-FAST)
// Collect the active key plus an optional previous key (kept during rotation so
// data encrypted with the old key still decrypts). Order matters: the first key
// encrypts; all keys are tried on decrypt.
// -----------------------------------------------------------------------------
$appKeys = array_values(array_filter([
    $env('APP_KEY', '') ?? '',
    $env('APP_KEY_PREVIOUS', '') ?? '',
], static fn(string $k): bool => $k !== ''));

// Refuse to boot in any non-local environment without a real key. Without one
// the encryption layer would silently fall back to an all-zero key, leaving
// encrypted cookies/sessions effectively unprotected. Better to fail loudly.
$appEnv = strtolower($env('APP_ENV', 'production') ?? 'production');
if ($appKeys === [] && !in_array($appEnv, ['local', 'testing'], true)) {
    throw new \RuntimeException(
        "APP_KEY is not set. Refusing to boot in '{$appEnv}' with an insecure "
        . 'fallback encryption key. Generate one with '
        . 'php -r "echo base64_encode(random_bytes(32)).PHP_EOL;".'
    );
}

// -----------------------------------------------------------------------------
// STEP 6 — PORT FACTORIES (LAZY)
// Ports are the kernel's seams to infrastructure (database, cache, mail, ...).
// Bind each as a CLOSURE so the implementation — and any connection it opens —
// is constructed only the first time a loaded module actually resolves it. A
// request that never touches the database pays nothing for it.
//
// Add more ports here as your project grows, e.g.:
//   CachePort::class => static fn() => new RedisCache(...),
//   MailPort::class  => static fn() => new SmtpMailer(...),
// -----------------------------------------------------------------------------
$ports = [
    CachePort::class => static fn(): InMemoryCache => new InMemoryCache(),

    DatabasePort::class => static fn(): PdoDatabase => new PdoDatabase(
        dsn: $env('DB_DSN', 'sqlite::memory:') ?? 'sqlite::memory:',
        username: $env('DB_USERNAME'),
        password: $env('DB_PASSWORD'),
    ),
    HashingPort::class => static fn(): PasswordHasher => new PasswordHasher(
        cost: (int) ($env('HASH_BCRYPT_COST', '12') ?? '12'),
    ),

    EncryptionPort::class => static fn(): AesEncrypter => new AesEncrypter(
        $appKeys === [] ? str_repeat('0', 32) : $appKeys,
    ),

        // File-backed queue (cross-process, no Redis). RedisCache overrides this
        // when REDIS_HOST is set. Lets `php app/worker/run.php` drain real jobs.
    QueuePort::class => static fn(): FileQueue => new FileQueue($projectRoot . '/var/queue'),

        // The SEO module subscribes EnqueueIndexNowListener to seo.url_published, but
        // the EventBus resolves listeners from the CoreContainer — so bind it here
        // with the QueuePort. (The factory receives the container.)
    EnqueueIndexNowListener::class => static fn($c) => new EnqueueIndexNowListener(
        $c->make(QueuePort::class),
    ),
];

if (filter_var($env('DB_POOL_ENABLED', 'false'), FILTER_VALIDATE_BOOL)) {
    $dbConfig = (new DatabaseConfigurationFactory())->fromEnvironment();
    $logQueries = filter_var($env('DB_ENABLE_QUERY_LOG', 'false'), FILTER_VALIDATE_BOOL);

    $pool = new ConnectionPool(
        factory: static fn(): MultiDriverDatabaseAdapter =>
        new MultiDriverDatabaseAdapter($dbConfig, null, $logQueries),
        config: PoolConfiguration::fromEnvironment(),
        driver: $dbConfig->driver(),
    );
    $pool->warmup();

    $ports[ConnectionPool::class] = $pool;
}


// -----------------------------------------------------------------------------
// STEP 7 — CONFIGURE, WIRE & BUILD THE KERNEL
// The fluent builder assembles everything. build() is compile-only (validates
// config + compiles route/view/job manifests); pipelines and modules are not
// materialized until the first http()/cli() call in the entry point.
// -----------------------------------------------------------------------------
return Kernel::configure()
    // Filesystem roots. Flat layout → base == project == root, so var/, config/,
    // database/ and userdata/ all resolve under this directory.
    ->withBasePath($projectRoot)
    ->withProjectPath($projectRoot)

    // Lazy infrastructure factories from STEP 6.
    ->withPorts($ports)

    // Project-layer routes declared in proj.json ("routes"). These map a
    // method+path to one of YOUR controllers (full class path) and resolve under
    // the synthetic '__project__' scope — no module register() runs for them.
    // Keep these controllers thin; real domain logic lives in plugins.
    ->withRoutes(EntryHelpers::projectRoutes($projectRoot))

    // Project ROUTE POLICY declared in proj.json ("routePolicy": {"disable": []}).
    // A plugin OWNS its routes, but the project is the final authority: it can
    // veto specific plugin routes ("METHOD /path") or a whole plugin's routes (a
    // module domain) without forking the plugin. Applied to plugin routes before
    // project routes compile — an unmatched spec fails the boot.
    ->withRoutePolicy(EntryHelpers::projectRoutePolicy($projectRoot))

    // Security layers run BEFORE any module loads — a denied request costs zero
    // module work. CsrfTokenLayer here is a stateless, HMAC-signed token
    // (WordPress-nonce style): the token is signed with APP_KEY and bound to the
    // opaque `csrf_bind` cookie, so no cookie VALUE is ever trusted as the token.
    // /api is exempt because APIs authenticate per request, not via a browser
    // CSRF token. Add a FirewallLayer / RateLimiterLayer here as needed.
    ->withSecurity([
        new CsrfTokenLayer(
            bindCookie: 'csrf_bind',
            exemptPaths: ['/api'],
        ),
    ])

    // ON-DEMAND modules: their boot() hooks register at build, but their
    // register() bindings only run when a route's dependency graph pulls them
    // in. Use for capabilities only SOME routes need (views, outbound HTTP,
    // storage). A route opts in via its "requires" in proj.json / module.json.
    ->withModules([
        // Crypto (solves: crypto) — provides the concrete AesEncrypter and
        // PasswordHasher classes behind the Encryption/Hashing port factories,
        // plus crypto helpers other modules consume.
        CryptoProvider::class,

        // I18n (solves: i18n) — translation/localisation: message catalogues,
        // locale negotiation, and the translator used by modules and views.
        I18nProvider::class,

        // Database (solves: database.query) — the multi-driver database stack:
        // the DatabasePort adapter, the pooled adapter that borrows from the
        // ConnectionPool, and connection/schema management.
        DatabaseProvider::class,

        // Commands (solves: commands) — registers this project's console
        // commands into the CLI pipeline (run via `php app/cli/run.php`).
        CommandsProvider::class,

        // Storage (solves: storage.local) — the StoragePort: file storage on the
        // local disk or S3/S3-compatible backends (atomic writes, streaming
        // up/download, signed temporary URLs). Routes opt in via
        // "requires": ["storage.local"].
        StorageProvider::class,

        // HttpClient (solves: http.client) — the HttpClientPort for OUTBOUND
        // HTTP (calling third-party APIs from gateways). Required by SiteSEO.
        HttpClientProvider::class,

        // View (solves: view.rendering) — server-side PHP templating: layouts,
        // sections, the project-first view cascade and `namespace::view`
        // resolution. Routes opt in via "requires": ["view.rendering"].
        ViewModule::class,

        // SiteSEO (solves: seo.management) — SEO toolkit: sitemaps, Open Graph,
        // JSON-LD, robots, IndexNow. Exposes SeoServiceContract + the /api/seo/*
        // routes. Needs http.client (above) for its network actions.
        SiteSeoModule::class,
    ])

    // ESSENTIAL modules: registered into EVERY request container regardless of
    // the route graph. Use sparingly for cross-cutting, REQUEST-SCOPED
    // infrastructure that can't be a stateless app-lifetime port — sessions,
    // cookies, a cache override. Keep their adapters self-guarding (no work
    // until first use) so idle requests stay cheap.
    ->withEssentialModules([
        // Session (solves: session.management) — the SessionPort: per-request
        // session state, flash data, and the CSRF token source.
        SessionProvider::class,

        // Cookie (solves: http.cookies) — the CookieJar plus the stage that
        // flushes queued cookies onto the Response (encrypting via EncryptionPort).
        CookieProvider::class,

        // RedisCache (solves: cache.redis) — when REDIS_HOST is set, OVERRIDES
        // the CachePort and QueuePort with Redis-backed adapters (shared across
        // workers). Falls back to the in-memory/file defaults when unset.
        RedisCacheProvider::class,

        // SecurityFilters (solves: http.security_filters) — global CORS +
        // SecureHeaders hooks, and the route filter aliases (auth, throttle,
        // hmac, shield). HMAC no longer runs globally — it fires ONLY on routes
        // that declare "filters": ["hmac"], so this is safe to enable app-wide.
        SecurityFiltersModule::class,
    ])

    // Compile-only. Returns the Kernel to the entry point, which materializes it
    // on the first http()/cli() call.
    ->build();
