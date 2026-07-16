<?php

declare(strict_types=1);

/**
 * =============================================================================
 *  HTTP FRONT CONTROLLER  (app/public/index.php)
 * =============================================================================
 *
 * The single entry point for ALL web traffic. Point your web server's document
 * root at this `app/public/` directory so every request funnels through here:
 *
 *   php -S localhost:8000 -t app/public           # built-in dev server
 *   nginx/apache  → docroot = <project>/app/public, rewrite all to index.php
 *
 * Flow:
 *   1. Load the autoloaders (kernel + plugins + your code).
 *   2. Require the project bootstrap, which returns a fully-built Kernel.
 *      (Bootstrap also set $domain — the resolved DomainContext — into scope.)
 *   3. Capture the real HTTP request, attach the domain context, hand it to the
 *      kernel's HTTP pipeline, and send the Response back to the browser.
 *   4. Any Throwable that escapes the pipeline becomes a safe JSON 500.
 *
 * Keep this file thin: it only adapts PHP's SAPI globals to the kernel and back.
 * All wiring lives in app/bootstrap/app.php; all logic lives in controllers and
 * plugins.
 * =============================================================================
 */

// 1. Autoloaders. The bootstrap also requires these, but the front controller
//    loads them first so the use-statements below resolve.
require_once __DIR__ . '/../bootstrap/kernel-autoload.php';
psp_require_kernel_autoload();

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;

// 2. Build the application. $domain (the resolved DomainContext, possibly null)
//    is defined inside the bootstrap and is in scope after this require.
/** @var \AlfacodeTeam\PhpServicePlatform\Kernel\Kernel $kernel */
$kernel = require __DIR__ . '/../bootstrap/app.php';

try {
    // 3. Build an immutable Request from the SAPI globals ($_SERVER, $_GET,
    //    $_POST, php://input, ...) and ride the resolved domain on it as an
    //    attribute (never via a global — coroutine/Swoole safe).
    $request = Request::capture();
    if (isset($domain) && $domain !== null) {
        $request = $request->withAttribute('domain', $domain);
    }

    // Run the HTTP pipeline (security → resolve → load → execute) and emit the
    // Response (status line, headers, body) to the client.
    $kernel->http()->handle($request)->send();
} catch (\Throwable $e) {
    // 4. Last-resort net for anything the kernel's own ErrorStage could not
    //    handle. In debug we surface the message; in production we never leak
    //    internals — just a generic 500.
    // Debug output is NEVER shown in production, even if APP_DEBUG was left true
    // (defense-in-depth against leaking internals from a mis-set .env).
    $isProd = (($_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: 'production') === 'production');
    $debug  = !$isProd && filter_var($_ENV['APP_DEBUG'] ?? getenv('APP_DEBUG') ?: 'false', FILTER_VALIDATE_BOOL);
    Response::json([
        'error' => [
            'code'    => 'kernel.unhandled',
            'message' => $debug ? $e->getMessage() : 'Internal Server Error',
        ],
    ], 500)->send();
}
