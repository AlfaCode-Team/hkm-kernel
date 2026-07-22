<?php

declare(strict_types=1);

namespace Plugins\Pageflow;

use AlfacodeTeam\PhpServicePlatform\Kernel\Contracts\ModuleContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Container\ModuleContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Cli\CliPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\HttpPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\WorkerPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\CachePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\SessionPort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Layers\CsrfTokenLayer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Support\Paths;
use Plugins\Pageflow\API\Contracts\PageflowSharerContract;
use Plugins\Pageflow\Http\PageflowAuth;
use Plugins\Pageflow\Http\PageflowChannel;
use Plugins\Pageflow\Http\PageflowResponder;
use Plugins\Pageflow\Http\PageflowStage;
use Plugins\Pageflow\Http\RegistryPageflowSharer;
use Plugins\Pageflow\Cli\PageflowTypesCommand;

/**
 * Pageflow plugin — server side of the Inertia-style SPA bridge.
 *
 * Controllers depend on PageflowResponder to return a component + props; the
 * responder negotiates JSON (XHR navigation) vs an HTML shell (initial load).
 * The version stage forces a hard reload when client assets are stale.
 *
 * The matching React client lives in the top-level frontend/ workspace.
 */
final class Provider implements ModuleContract
{
    public function solves(): string
    {
        return 'http.pageflow';
    }

    /** @return list<class-string> */
    public function requires(): array
    {
        return ['vite.manifest'];
    }

    /** @return list<class-string> */
    public function exposes(): array
    {
        return [PageflowResponder::class];
    }

    public function register(ModuleContainer $container): void
    {
        // Per-request singleton so share()/mergeShared() and render() see one bag.
        $container->singleton(PageflowResponder::class, static function () {
            // Mint the platform CSRF token for the current request so the client
            // can echo it back in X-CSRF-Token. The token is HMAC(APP_KEY, binding)
            // where the binding is the raw session-cookie value the CsrfTokenLayer
            // pins to (must be in Cookie's encrypt_exempt). Empty APP_KEY = no token
            // (mirrors the layer's fail-closed default; a GET-only app needs none).
            $csrfCookie = env('PAGEFLOW_CSRF_COOKIE') ?: 'hkm_session';
            // MUST match the lifetime configured on the CsrfTokenLayer in
            // withSecurity([...]); a mismatch makes valid() reject the token.
            $csrfLifetime = (int) (env('PAGEFLOW_CSRF_LIFETIME') ?: 43200);

            $csrfResolver = static function (Request $request) use ($csrfCookie, $csrfLifetime): string {
                $secret = (string) (env('APP_KEY') ?: '');
                if ($secret === '') {
                    return '';
                }
                $binding = (string) ($request->cookie($csrfCookie) ?? '');
                return CsrfTokenLayer::make($secret, $binding, $csrfLifetime);
            };

            // Root view (HTML shell for the initial load). A relative PAGEFLOW_ROOT_VIEW
            // is resolved against the ACTIVE PROJECT ROOT so it loads regardless of the
            // process CWD (the kernel rarely runs from the project dir); an absolute
            // path is honoured as-is.
            $rootView   = (string) (env('PAGEFLOW_ROOT_VIEW') ?: 'resources/layouts/app.php');
            $isAbsolute = $rootView !== ''
                && ($rootView[0] === '/' || preg_match('#^[A-Za-z]:[\\\\/]#', $rootView) === 1);
            $layoutPath = $isAbsolute ? $rootView : Paths::project($rootView);

            return new PageflowResponder(
                version:      env('PAGEFLOW_VERSION') ?: '1',
                layoutPath:   $layoutPath,
                appId:        env('PAGEFLOW_APP_ID') ?: 'app',
                csrfResolver: $csrfResolver,
            );
        });

        // Default sharer: runs every contributor registered via pageflow_share().
        // Bind your own PageflowSharerContract in the project to override.
        $container->bind(PageflowSharerContract::class, static fn() => new RegistryPageflowSharer());

        // Emit side of reactive props. Depends on the (essential) CachePort, so
        // it is resolvable from every request. Inject it into a Service to touch
        // channels after a commit, or into a controller to open the SSE stream.
        $container->bind(PageflowChannel::class, static fn($c) => new PageflowChannel(
            $c->make(CachePort::class),
        ));
    }

    public function boot(HttpPipeline $http, CliPipeline $cli, WorkerPipeline $worker, EventBus $events): void
    {
        // One stage carries the whole Pageflow HTTP protocol at after.load (the
        // container/responder exist there): stale-asset 409 guard, precognition
        // flagging, shared-prop population (do_action('pageflow_share')), and the
        // ValidationException → Pageflow error envelope translation around $next.
        $http->hook('after.load', PageflowStage::class, priority: 40);

        // CLI: generate TypeScript typings (shared props + page registry).
        $cli->command(PageflowTypesCommand::class);

        // ── Built-in shared props ────────────────────────────────────────────
        // Auth projection on every page (UI: useAuth()/<Can>). NON-SENSITIVE
        // fields only — never tokens. This is for UX gating; the Service layer
        // remains the real authorization boundary.
        pageflow_share('pageflow_auth', static function (Request $request): array {
            return PageflowAuth::resolve($request->identity());
        });

        // Validation errors flashed by PageflowStage on the previous
        // request surface here (UI: useForm reads props.errors). Pull-and-clear.
        pageflow_share('errors', static function (Request $request): array {
            $container = $request->container();
            if ($container === null || !$container->has(SessionPort::class)) {
                return [];
            }
            /** @var SessionPort $session */
            $session = $container->make(SessionPort::class);
            $errors = $session->pull(PageflowStage::ERROR_FLASH_KEY, []);
            return is_array($errors) ? $errors : [];
        });
    }
}
