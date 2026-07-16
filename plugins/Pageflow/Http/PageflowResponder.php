<?php

declare(strict_types=1);

namespace Plugins\Pageflow\Http;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;

/**
 * Server side of the Pageflow (Inertia-style) protocol.
 *
 * A controller returns a component name + props; this responder decides the
 * wire format:
 *   - XHR pageflow request (X-Pageflow header, or Accept: application/json)
 *       -> JSON page object
 *   - full page load
 *       -> HTML document that boots the client from `window.initialPage`
 *
 * Wire protocol matches the legacy HKM\lib\PageFlow implementation:
 *   - initial load renders a PHP layout template via ob_start (the template has
 *     $FLOW_PAGE in scope and echoes $FLOW_PAGE->renderScript()), exposing the
 *     page object as `window.initialPage` so existing clients boot unchanged;
 *   - shared data (see share()/mergeShared()) is merged into every page's props,
 *     page props winning on key collision;
 *   - partial reloads honour X-Pageflow-Partial-Data / -Except / -Component;
 *   - loadPage:false navigations rewrite url/component from
 *     X-Pageflow-Url / X-Pageflow-Page.
 *
 * Shared data is held per instance (NOT static) so it is request-scoped and
 * safe under OpenSwoole; the module binds the responder as a per-request
 * singleton so share() and render() see the same bag.
 */
final class PageflowResponder
{
    /** @var array<string,mixed> */
    private array $shared = [];

    /**
     * @param string $layoutPath absolute path to the PHP layout (root view) template
     *                           rendered via ob_start for a full page load. The
     *                           Provider resolves a relative PAGEFLOW_ROOT_VIEW
     *                           against the active project root. In scope:
     *                           $FLOW_PAGE (PageflowPage), $FLOW_CSRF (string
     *                           token), $FLOW_APP_ID (root element id). Empty
     *                           falls back to a minimal document.
     * @param (\Closure(Request):string)|null $csrfResolver mints the CSRF token
     *                           for the current request so the client can echo it
     *                           back in the X-CSRF-Token header. Null = no token
     *                           (safe/GET-only apps).
     */
    public function __construct(
        private readonly string $version,
        private readonly string $layoutPath,
        private readonly string $appId = 'app',
        private readonly ?\Closure $csrfResolver = null,
    ) {
    }

    /**
     * Signal that a PRECOGNITION request passed validation with no side effects.
     * Return this from a controller after building a validated DTO when
     * pageflow_precognition($request) is true. The client treats 2xx as "valid".
     */
    public function precognitionSuccess(): Response
    {
        return Response::json(['errors' => (object) []], 200, [
            'X-Pageflow'           => 'true',
            'Precognition'         => 'true',
            'Precognition-Success' => 'true',
            'Vary'                 => 'X-Pageflow, Precognition',
        ]);
    }

    /**
     * Fresh-CSRF endpoint. Wire a project route to this (GET /pageflow/csrf) so a
     * long-lived SPA can refresh a token that expired since page load and avoid a
     * spurious 403 on its next mutation. Never cached.
     */
    public function csrfResponse(Request $request): Response
    {
        return Response::json(['token' => $this->csrfFor($request)], 200, [
            'Cache-Control' => 'no-store',
        ]);
    }

    /** Mint the CSRF token for this request, or '' when unavailable. */
    private function csrfFor(Request $request): string
    {
        if ($this->csrfResolver === null) {
            return '';
        }

        return (string) ($this->csrfResolver)($request);
    }

    /** Register a single shared prop present on every rendered page. */
    public function share(string $key, mixed $value): void
    {
        $this->shared[$key] = $value;
    }

    /**
     * Merge many shared props at once.
     *
     * @param array<string,mixed> $data
     */
    public function mergeShared(array $data): void
    {
        $this->shared = array_merge($this->shared, $data);
    }

    /**
     * @param string $surface the `hkm ui` surface (buildable app: "admin",
     *                       "project", …) whose bundle boots this page. Required —
     *                       it selects which Vite entry/manifest the HTML shell
     *                       loads (see resources/layouts/app.php), so a page is
     *                       always rendered into the intended surface.
     * @param array<string,mixed> $props
     * @param string|null $viteEntry override the Vite entry point (manifest key,
     *                       relative to the frontend/ root) for the HTML shell.
     *                       Null (default) → the surface's conventional entry
     *                       "src/surfaces/{surface}/index.tsx".
     * @param bool $loadPage false for a client-driven partial navigation that
     *                       supplies its own url/component via headers.
     * @param bool $cacheable opt THIS page into the service-worker offline cache
     *                       (adds X-Pageflow-Cache: 1). Use ONLY for pages with no
     *                       user-specific data — the SW caches nothing by default.
     */
    public function render(
        Request $request,
        string $component,
        string $surface,
        array $props = [],
        ?string $viteEntry = null,
        bool $loadPage = true,
        bool $cacheable = false,
    ): Response {
        // Shared props first, page props override (matches legacy array_merge).
        $props = array_merge($this->shared, $props);

        // SECURITY: the CSRF token is intentionally NOT injected as a prop. The
        // client reads it from the <meta name="csrf-token"> tag on the HTML shell.
        // Keeping it out of the page-object JSON stops it landing in XHR response
        // bodies and the service-worker page cache. It still rides the HTML head
        // below (htmlResponse) for the initial load; long-lived tabs refresh via
        // GET /pageflow/csrf.
        $csrf = $this->csrfFor($request);

        $props = $this->resolvePartial($request, $component, $props);

        $url = $this->fullUrl($request);
        if (!$loadPage) {
            $headerUrl = (string) ($request->header('X-Pageflow-Url') ?? '');
            $parsed = $headerUrl !== '' ? (parse_url($headerUrl, PHP_URL_PATH) ?: $url) : $url;
            $url = (string) $parsed;
            $component = (string) ($request->header('X-Pageflow-Page') ?? $component);
        }

        $page = new PageflowPage(
            component: $component,
            props:     $props,
            url:       $url,
            version:   $this->version,
        );

        return $this->isPageflow($request)
            ? $this->jsonResponse($page, $cacheable)
            : $this->htmlResponse($page, $csrf, $surface, $viteEntry, $cacheable);
    }

    /**
     * A pageflow (XHR) request carries the X-Pageflow header (any value) OR
     * negotiates JSON via Accept — matching the legacy detection.
     */
    public function isPageflow(Request $request): bool
    {
        if ((string) ($request->header('X-Pageflow') ?? '') !== '') {
            return true;
        }

        return str_contains(strtolower((string) ($request->header('Accept') ?? '')), 'application/json');
    }

    private function jsonResponse(PageflowPage $page, bool $cacheable = false): Response
    {
        $headers = [
            'X-Pageflow' => 'true',
            'Vary'       => 'X-Pageflow',
        ];
        // Opt-in offline caching (SW honours X-Pageflow-Cache). Default off so
        // authenticated page objects are never cached at rest by accident.
        if ($cacheable) {
            $headers['X-Pageflow-Cache'] = '1';
        }

        return Response::json($page->toArray(), 200, $headers);
    }

    private function htmlResponse(PageflowPage $page, string $csrf, string $surface, ?string $viteEntry, bool $cacheable = false): Response
    {
        $html = ($this->layoutPath !== '' && is_file($this->layoutPath))
            ? $this->renderLayout($this->layoutPath, $page, $csrf, $surface, $viteEntry)
            : $this->defaultDocument($page, $csrf);

        $response = Response::text($html, 200)->withHeader('Content-Type', 'text/html; charset=UTF-8');

        return $cacheable ? $response->withHeader('X-Pageflow-Cache', '1') : $response;
    }

    /**
     * Render a PHP layout template, capturing its output with ob_start. The
     * template runs in an isolated scope with only the Pageflow variables
     * available ($FLOW_PAGE, $FLOW_CSRF, $FLOW_APP_ID, $FLOW_SURFACE,
     * $FLOW_VITE_ENTRY) — no globals leak in.
     */
    private function renderLayout(string $path, PageflowPage $page, string $csrf, string $surface, ?string $viteEntry): string
    {
        $capture = static function (
            string $__path,
            PageflowPage $FLOW_PAGE,
            string $FLOW_CSRF,
            string $FLOW_APP_ID,
            string $FLOW_SURFACE,
            ?string $FLOW_VITE_ENTRY
        ): string {
            ob_start();
            try {
                require $__path;
            } catch (\Throwable $e) {
                ob_end_clean();
                throw $e;
            }
            return ob_get_clean() ?: '';
        };

        return $capture($path, $page, $csrf, $this->appId, $surface, $viteEntry);
    }

    /**
     * Partial reload: when the client asks for a subset of props on the same
     * component, return only those (or all except the excluded ones).
     *
     * @param array<string,mixed> $props
     * @return array<string,mixed>
     */
    private function resolvePartial(Request $request, string $component, array $props): array
    {
        $only   = $this->headerList($request, 'X-Pageflow-Partial-Data');
        $except = $this->headerList($request, 'X-Pageflow-Partial-Except');
        $target = (string) ($request->header('X-Pageflow-Partial-Component') ?? '');

        // Partial rules only apply when the requested component matches.
        if (($only === [] && $except === []) || ($target !== '' && $target !== $component)) {
            return $props;
        }

        if ($only !== []) {
            $props = array_intersect_key($props, array_flip($only));
        }
        if ($except !== []) {
            $props = array_diff_key($props, array_flip($except));
        }

        return $props;
    }

    /** @return list<string> */
    private function headerList(Request $request, string $name): array
    {
        $raw = (string) ($request->header($name) ?? '');
        if ($raw === '') {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode(',', $raw)), static fn(string $s) => $s !== ''));
    }

    private function fullUrl(Request $request): string
    {
        $path = $request->path();
        $query = http_build_query($request->queryAll());
        return $query === '' ? $path : $path . '?' . $query;
    }

    private function defaultDocument(PageflowPage $page, string $csrf): string
    {
        // The client boots from the root element's data-page attribute — NOT
        // window.initialPage. PageflowPage::mount() emits the correct element.
        $csrfMeta = $csrf !== ''
            ? '<meta name="csrf-token" content="' . htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') . '">'
            : '';

        // Reserved `seoHead` prop (see the stock layout): render it into <head>
        // and STRIP it from the client payload — it is server-only HTML, and
        // shipping it in data-page would only bloat the boot JSON. A plain-text
        // value (the XHR tab-title string) renders as an escaped <title>.
        $seoHead = (string) ($page->props['seoHead'] ?? '');
        if ($seoHead !== '') {
            $page = new PageflowPage(
                component:      $page->component,
                props:          array_diff_key($page->props, ['seoHead' => true]),
                url:            $page->url,
                version:        $page->version,
                clearHistory:   $page->clearHistory,
                encryptHistory: $page->encryptHistory,
            );
        }
        $seoBlock = match (true) {
            $seoHead === ''                => '',
            str_contains($seoHead, '<')    => $seoHead,
            default                        => '<title>' . htmlspecialchars($seoHead, ENT_QUOTES, 'UTF-8') . '</title>',
        };

        return '<!DOCTYPE html>' . "\n"
            . '<html><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . $csrfMeta
            . $seoBlock
            . '</head><body>' . $page->mount($this->appId) . '</body></html>';
    }
}
