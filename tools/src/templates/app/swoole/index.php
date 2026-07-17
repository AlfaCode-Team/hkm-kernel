<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/kernel-autoload.php';
psp_require_kernel_autoload();

/**
 * =============================================================================
 *  OpenSwoole HTTP ENTRY POINT  (app/swoole/index.php)
 * =============================================================================
 *
 * A high-performance ALTERNATIVE to app/public/index.php. Instead of PHP-FPM
 * booting the framework fresh on every request, OpenSwoole boots the kernel ONCE
 * per long-lived worker process and keeps it resident in memory — so subsequent
 * requests skip all bootstrap cost. Use this for throughput-sensitive
 * deployments; keep app/public/index.php for simple PHP-FPM/Apache hosting.
 *
 * Run it directly (it IS the server — there is no external web server in front
 * unless you add a reverse proxy):
 *
 * Boots the kernel ONCE per worker process, then routes every HTTP request
 * through the kernel's HttpPipeline:
 *
 *     Swoole request → Request value object → $kernel->http()->handle($request)
 *                    → Response → Swoole response → $kernel->requestTeardown()
 *
 * OpenSwoole memory-safety contract
 * ---------------------------------
 *   - The kernel is built per worker in `workerStart` and stored on the worker.
 *     CoreContainer is frozen after build() (writes throw), so cross-request
 *     mutation is impossible.
 *   - A fresh ModuleContainer is created per request inside the pipeline's
 *     LoadStage and discarded when the request finishes.
 *   - PHP superglobals are NEVER read — every input comes from the Swoole
 *     request object.
 *   - `enable_coroutine` defaults to FALSE: requests run sequentially per worker
 *     and concurrency comes from multiple workers. Set SWOOLE_COROUTINE=true only
 *     after verifying the whole request path is coroutine-safe.
 *
 * Usage
 * -----
 *   php app/swoole/index.php
 *   # config comes from .env; real env vars still override (they win in env()):
 *   SWOOLE_PORT=9502 HKM_WORKERS=8 php app/swoole/index.php
 *
 * Configuration (read from .env via the env() helper; OS env overrides .env)
 * --------------------------------------------------------------------------
 *   SWOOLE_HOST        127.0.0.1   Listen host (keep internal; front with a gateway)
 *   SWOOLE_PORT        9502        Listen port
 *   HKM_WORKERS        cpu_count   Worker process count
 *   HKM_ENV            production  production | development
 *   SWOOLE_COROUTINE   false       Enable coroutine concurrency (advanced)
 *   SWOOLE_MAX_REQUEST 0           Recycle worker after N requests (0 = never)
 *   SWOOLE_DAEMONIZE   false       Run the server in the background
 */

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\UploadedFile;
use AlfacodeTeam\PhpServicePlatform\Kernel\Kernel;
use Project\Bootstrap\EntryHelpers;
use Project\Bootstrap\Environment\LoadEnvironment;
use OpenSwoole\Http\Request as SwooleRequest;
use OpenSwoole\Http\Response as SwooleResponse;
use OpenSwoole\Http\Server as SwooleServer;


// ── 0. OpenSwoole presence guard ─────────────────────────────────────────────
if (!class_exists(SwooleServer::class)) {
    fwrite(STDERR, "[{{PROJECT_NAME}}] OpenSwoole extension not loaded. Add `extension=openswoole` to php.ini.\n");
    exit(1);
}

// ── 1. Server configuration (from .env) ──────────────────────────────────────
// Load the base .env in the MASTER process so the server's own settings can come
// from .env. Read via the env() helper (the canonical reader: $_ENV/$_SERVER
// first, then real OS env) — never getenv(), which does not see .env values.
// Workers reload .env in workerStart for their per-project cascade + kernel.
$rootPath = dirname(__DIR__, 2);
LoadEnvironment::load($rootPath);

$host = (string) (env('SWOOLE_HOST') ?: '127.0.0.1');
$port = (int) (env('SWOOLE_PORT') ?: 9502);
$workers = (int) (env('HKM_WORKERS') ?: (function_exists('swoole_cpu_num') ? swoole_cpu_num() : 4));
$env = (string) (env('HKM_ENV') ?: 'production');
$coroutine = filter_var(env('SWOOLE_COROUTINE') ?: 'false', FILTER_VALIDATE_BOOLEAN);

$server = new SwooleServer($host, $port);
$server->set([
    'worker_num' => $workers,
    'enable_coroutine' => $coroutine,
    'max_request' => (int) (env('SWOOLE_MAX_REQUEST') ?: 0),
    'reload_async' => true,
    'max_wait_time' => 60,
    'open_tcp_nodelay' => true,
    'socket_buffer_size' => 8 * 1024 * 1024,
    'buffer_output_size' => 32 * 1024 * 1024,
    'daemonize' => filter_var(env('SWOOLE_DAEMONIZE') ?: 'false', FILTER_VALIDATE_BOOLEAN),
]);

// ── 2. WorkerStart — build the kernel ONCE per worker ────────────────────────
// NOTE: We boot the kernel of the *env-selected* project here. Per-request domain
// resolution attaches a DomainContext attribute to the Request so modules can react
// to the actual host the client hit (admin vs api vs project face) without needing
// a separate kernel per host. If you need a hard per-host kernel split, run a
// dedicated worker pool per project (e.g. one server.php instance per HKM_PROJECT).
//
// Per-worker kernel, captured by reference across the worker/request closures.
// OpenSwoole forks each worker before these closures run, so every worker owns
// its own copy — no cross-worker sharing. Avoids a dynamic property on the
// Server object (deprecated in PHP 8.4, fatal in PHP 9).
/** @var Kernel|null $kernel */
$kernel = null;

$server->on('workerStart', static function (SwooleServer $server, int $workerId) use ($rootPath, &$kernel): void {

    $kernel = require $rootPath . '/app/bootstrap/app.php';
    error_log("[{{PROJECT_NAME}}] Worker #{$workerId} ready (project={{PROJECT_NAME}})");
});

// ── 3. WorkerStop — release the worker's kernel ──────────────────────────────
$server->on('workerStop', static function (SwooleServer $server, int $workerId) use (&$kernel): void {
    $kernel = null;
    error_log("[{{PROJECT_NAME}}] Worker #{$workerId} stopped");
});

// ── 4. Request handler — route through the kernel HttpPipeline ────────────────
$server->on('request', static function (SwooleRequest $req, SwooleResponse $res) use ($env, $rootPath, &$kernel): void {

    try {
        $request = buildRequest($req);

        // Per-request domain resolution. Uses a worker-level cache keyed by basePath,
        // so this is a cheap array lookup after the first request in each worker.
        $hostHeader = $req->header['host'] ?? null;
        $domain = EntryHelpers::resolveDomain($rootPath, is_string($hostHeader) ? $hostHeader : null);
        if ($domain !== null) {
            $request = $request->withAttribute('domain', $domain);
        }

        $response = $kernel->http()->handle($request);

        $res->status($response->status());
        foreach ($response->headers() as $name => $value) {
            $res->header($name, $value);
        }
        // Cookies are tracked separately from headers (see Response::cookies());
        // emit each as a Set-Cookie line so they survive under OpenSwoole too.
        foreach ($response->cookies() as $cookie) {
            $res->header('Set-Cookie', $cookie);
        }

        if ($response->isFile() && $response->filePath() !== null && is_file($response->filePath())) {
            // Zero-copy file transfer; sendfile() finalises the response itself.
            $res->sendfile($response->filePath());
        } elseif ($response->isStreamed()) {
            // Pipe chunks straight to the socket without buffering the whole body.
            $response->streamTo(static fn(string $chunk): bool => $res->write($chunk));
            $res->end();
        } else {
            $res->end($response->body());
        }
    } catch (\Throwable $e) {
        // The pipeline's ErrorStage normally handles this; this is the last resort.
        $res->status(500);
        $res->header('Content-Type', 'application/json');
        $res->end(json_encode([
            'error' => [
                'code' => 'kernel.unhandled',
                'message' => $env === 'development' ? $e->getMessage() : 'Internal Server Error',
            ],
        ]));
    } finally {
        $kernel?->requestTeardown();
    }
});

$server->on('shutdown', static function (): void {
    echo "[{{PROJECT_NAME}}] Server shutting down\n";
});

/**
 * Translate an OpenSwoole request into a kernel Request value object.
 * No PHP superglobals are touched.
 */
function buildRequest(SwooleRequest $req): Request
{
    $method = strtoupper((string) ($req->server['request_method'] ?? 'GET'));
    $path = (string) ($req->server['request_uri'] ?? '/');
    $headers = $req->header ?? [];
    $query = $req->get ?? [];
    $rawBody = (string) $req->rawContent();
    $body = $req->post ?? [];

    $contentType = $headers['content-type'] ?? '';
    if ($rawBody !== '' && str_contains($contentType, 'application/json')) {
        $decoded = json_decode($rawBody, true);
        if (is_array($decoded)) {
            $body = $decoded;
        }
    }

    // Map Swoole's $_FILES-shaped uploads into kernel UploadedFile objects built
    // in test mode (their temp files were not created by PHP's multipart handler,
    // so is_uploaded_file() would reject a real-upload wrapper).
    $files = [];
    foreach ($req->files ?? [] as $field => $file) {
        $files[$field] = is_array($file) && isset($file['tmp_name']) && !is_array($file['tmp_name'])
            ? UploadedFile::fromSwoole($file)
            : $file;
    }

    return Request::build(
        method: $method,
        path: $path,
        headers: $headers,
        query: $query,
        body: $body,
        rawBody: $rawBody,
        cookies: $req->cookie ?? [],
        files: $files,
    );
}

echo "[{{PROJECT_NAME}}] OpenSwoole HTTP server  http://{$host}:{$port}  env={$env}  workers={$workers}"
    . "  coroutine=" . ($coroutine ? 'ON' : 'OFF') . "\n";

$server->start();
