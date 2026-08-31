<?php

declare(strict_types=1);

/**
 * =============================================================================
 *  PROJECT BOOTSTRAP  (app/bootstrap/app.php)  —  SIMPLE
 * =============================================================================
 *
 * The whole application, assembled in one place. Every entry point — the web
 * front controller (app/public/index.php) and the CLI runner (app/cli/run.php)
 * — `require`s this file and receives a configured Kernel back. It RETURNS the
 * kernel rather than running it, so each surface drives the same wiring:
 * `$kernel->http()->handle(...)` for web, `$kernel->cli()->run(...)` for the
 * terminal.
 *
 * -----------------------------------------------------------------------------
 *  WHY THIS ONE IS EMPTY
 * -----------------------------------------------------------------------------
 * No plugins are enabled. Not "none yet" — none, deliberately.
 *
 * The framework loads only what a request actually needs, so a plugin you have
 * not enabled costs nothing at runtime. It does cost something everywhere else:
 * a download, a directory, a line of wiring, a version to keep current, and one
 * more thing to understand before you can read your own bootstrap. Starting at
 * zero means everything present here is something you asked for.
 *
 * Add one when a requirement arrives, not in case it does:
 *
 *     hkm plugins install database      # DatabasePort, migrations
 *     hkm plugins install view          # PHP templates
 *     hkm plugins install auth          # login, tokens, sessions
 *
 * `hkm plugins install` fetches the plugin AND the plugins it depends on, wires
 * them into this file in dependency order, and publishes their config and
 * migrations. `hkm plugins list` shows what is enabled; `hkm plugins domains`
 * shows which plugin provides a capability you are looking for.
 *
 * The full starter (`hkm new <path>`, without --simple) comes with a working
 * database, session, cookie, cache, view and validation stack already wired.
 *
 * -----------------------------------------------------------------------------
 *  BOOT ORDER (top to bottom — the order matters)
 * -----------------------------------------------------------------------------
 *   1. autoload         find the kernel, register the class loaders
 *   2. environment      load the .env cascade BEFORE anything reads config
 *   3. error net        catch failures that happen before the kernel is live
 *   4. kernel           declare paths, routes, security, modules
 *   5. build            compile manifests and hand the kernel back
 */

// -----------------------------------------------------------------------------
// STEP 0 — AUTOLOAD
// kernel-autoload.php only DEFINES the resolver; calling it is what actually
// registers the kernel's class loaders. Requiring the file and forgetting the
// call leaves every framework class undefined, and the failure surfaces on the
// first one used rather than here.
// -----------------------------------------------------------------------------
if (!function_exists('hkm_require_kernel_autoload') || !function_exists('hkm_kernel_home')) {
    require_once __DIR__ . '/kernel-autoload.php';
}
hkm_require_kernel_autoload();

use AlfacodeTeam\PhpServicePlatform\Kernel\Kernel;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\CachePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Layers\CsrfTokenLayer;

use Project\Bootstrap\EntryHelpers;
use Project\Infrastructure\FileCache;
use Project\Infrastructure\LazyDatabasePort;
use Project\Infrastructure\PdoDatabase;
use Project\Bootstrap\Environment\ErrorGuard;
use Project\Bootstrap\Environment\LoadEnvironment;

// -----------------------------------------------------------------------------
// STEP 1 — PATHS
// Flat layout: the scaffolded directory IS the project, so this file's
// grandparent (bootstrap → app → root) is the project root.
// -----------------------------------------------------------------------------
$projectRoot = dirname(__DIR__, 2);

// -----------------------------------------------------------------------------
// STEP 2 — DOMAIN RESOLUTION
// Turn the request's Host header into a DomainContext (which project face is
// being served, and its features). Null under CLI and workers — no Host header
// there, which is expected and handled downstream.
// -----------------------------------------------------------------------------
$domain = EntryHelpers::resolveDomain($projectRoot, $_SERVER['HTTP_HOST'] ?? null);

// -----------------------------------------------------------------------------
// STEP 3 — ENVIRONMENT
// Load .env before anything reads configuration. Real process environment
// always wins, so server config is never clobbered by a file.
//
// Values land in $_ENV/$_SERVER and NOT in putenv(), so read them with the
// env() helper — getenv() will not see them.
// -----------------------------------------------------------------------------
LoadEnvironment::load($projectRoot, $domain, $_SERVER['argv'] ?? null);

// -----------------------------------------------------------------------------
// STEP 4 — PRE-KERNEL ERROR NET
// The outer safety net, for failures the kernel's own error pipeline cannot
// catch because it is not running yet: parse errors, fatals, out-of-memory.
// Writes to the same log the kernel uses, so everything lands in one file.
// -----------------------------------------------------------------------------
ErrorGuard::install($projectRoot . '/var/logs/errors.log');

// -----------------------------------------------------------------------------
// STEP 5 — THE KERNEL
// -----------------------------------------------------------------------------
return Kernel::configure()

    // Where things live. Flat layout, so both are the project root.
    ->withBasePath($projectRoot)
    ->withProjectPath($projectRoot)

    // -------------------------------------------------------------------------
    // PORTS
    // -------------------------------------------------------------------------
    // The kernel requires a DatabasePort and a CachePort to be bound before it
    // will boot. These two are the kernel's OWN implementations — no plugin
    // involved — so an empty project starts and serves immediately.
    //
    // Both are deliberately modest, and both are meant to be replaced:
    //
    //     hkm plugins install database     // pooled multi-driver adapter
    //     hkm plugins install redis-cache  // Redis CachePort + QueuePort
    //
    // Installing either one rewrites the binding below to use it.
    ->withPorts([
        // Lazy: the closure runs on FIRST USE, not at boot. A project with no
        // database configured therefore boots and serves normally, and only a
        // request that actually touches the database pays for a connection —
        // or fails, which is the honest moment to find out DB_DSN is unset.
        DatabasePort::class => new LazyDatabasePort(
            static fn (): PdoDatabase => new PdoDatabase(
                env('DB_DSN', 'sqlite:' . $projectRoot . '/var/database.sqlite'),
                env('DB_USERNAME'),
                env('DB_PASSWORD'),
            ),
        ),

        // File-backed, so a cached value survives between requests under
        // PHP-FPM (an in-memory cache would not — each request is a new
        // process, and every read would miss).
        CachePort::class => new FileCache($projectRoot . '/var/cache/data'),
    ])

    // Routes come from proj.json — never from PHP. Declaring them as data is
    // what lets the kernel compile a route manifest at build time and resolve a
    // request without loading a single module.
    ->withRoutes(EntryHelpers::projectRoutes($projectRoot))
    // Route GROUPS from proj.json: a prefix / filters / requires / name
    // prefix / SITE stated once for every route inside the group, and
    // expanded into flat routes at boot. `site` is part of the route key,
    // so one project can answer `GET /` differently per group of hosts.
    ->withRouteGroups(EntryHelpers::projectRouteGroups($projectRoot))
    // The hosts this project serves. A route grouped under a domain that is
    // not in proj.json "domains" fails the boot — nothing could ever reach it.
    ->withProjectDomains(EntryHelpers::projectDomains($projectRoot))

    // A project can also switch OFF a route a plugin declares, without forking
    // the plugin: proj.json "routePolicy": { "disable": ["GET /register"] }.
    ->withRoutePolicy(EntryHelpers::projectRoutePolicy($projectRoot))

    ->withSecurity([
        // The only security layer the kernel ships: stateless HMAC-signed CSRF
        // tokens. Nothing is stored and no cookie value is trusted as the
        // token, so cookie injection cannot bypass it.
        //
        // The secret defaults to APP_KEY. An EMPTY APP_KEY fails closed — every
        // state-changing request is denied — so set one before serving traffic:
        //     hkm key:generate
        new CsrfTokenLayer(
            headerName: 'X-CSRF-Token',
            formField:  '_csrf_token',
            lifetime:   43200, // 12 hours, in seconds
            // Paths that never carry a browser session; APIs authenticate with
            // a token instead, for which CSRF is meaningless.
            exemptPaths: ['/api'],
        ),

        // Authentication is NOT here. The kernel ships no token validator on
        // purpose — add the Auth plugin and its layers when you need accounts:
        //     hkm plugins install auth
    ])

    // -------------------------------------------------------------------------
    // MODULES
    // -------------------------------------------------------------------------
    // Empty, and that is the point of --simple. `hkm plugins install <name>`
    // adds entries here for you, in dependency order, with a comment saying
    // what each one solves.
    //
    // A module listed here is loaded ON DEMAND: only when a route being served
    // needs it. Listing one costs nothing until something asks for it.
    ->withModules([
        //
    ])

    // -------------------------------------------------------------------------
    // ESSENTIAL MODULES
    // -------------------------------------------------------------------------
    // Registered into EVERY request, needed or not. Reserve this for
    // cross-cutting request-scoped infrastructure (sessions, cookies) that
    // cannot be an app-lifetime port — and keep the list short, because each
    // entry and its whole dependency graph registers on every single request.
    //
    // Read from proj.json "essentials": [...], so which plugins are global is a
    // deployment decision rather than a code edit.
    ->withEssentialModules(EntryHelpers::projectEssentials($projectRoot))

    // Compile-only: this validates config and compiles the manifests. The
    // entry point materializes the kernel on its first http()/cli() call.
    ->build();
