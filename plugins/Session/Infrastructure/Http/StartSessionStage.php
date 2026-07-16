<?php

declare(strict_types=1);

namespace Plugins\Session\Infrastructure\Http;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\SessionPort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\Contracts\HttpStageContract;
use Plugins\Session\Infrastructure\Handlers\Contracts\CookieBackedHandler;
use Plugins\Session\Infrastructure\Store;

/**
 * StartSessionStage — opens the session before modules run and persists it after
 * (GDA rewrite of the 0.3 StartSession filter).
 *
 * Registered at `after.load` so the request-scoped ModuleContainer (and the
 * SessionPort the Session provider bound into it) is available. It:
 *   1. loads the session keyed by the incoming session cookie,
 *   2. runs the rest of the pipeline,
 *   3. remembers the visited page (login "previous page" redirect target),
 *   4. saves the session and writes the session cookie onto the response.
 *
 * Stateless across requests — all state lives in the per-request Store instance.
 */
final class StartSessionStage implements HttpStageContract
{
    /**
     * Session key holding the last non-auth page visited (relative path +
     * query) — the SINGLE source of truth for this key. The Auth/SocialAuth
     * login flows PULL it (one-time) to redirect the user back after a
     * successful sign-in.
     */
    public const PREVIOUS_URL = 'auth.previous_url';

    /** Longest URL worth remembering — anything bigger is dropped, not truncated. */
    private const PREVIOUS_URL_MAX = 2048;

    /**
     * Path prefixes that must never become a post-login redirect target: the
     * auth surface itself (login/logout/registration/password/OAuth) and the
     * JSON surfaces (/api, /ajx) that are not browser pages. Extend per
     * deployment with SESSION_PREVIOUS_EXEMPT (comma-separated prefixes).
     */
    private const PREVIOUS_URL_EXEMPT = [
        '/auth', '/oauth', '/api', '/ajx',
        '/login', '/logout', '/register', '/password',
        '/verify-email', '/users/verify',
    ];

    public function handle(Request $request, callable $next): Response
    {
        $container = $request->container();
        if ($container === null || !$container->has(SessionPort::class)) {
            return $next($request); // session not configured for this request
        }

        $session = $container->make(SessionPort::class);
        if (!$session instanceof SessionPort) {
            return $next($request);
        }

        $cookieName    = (string) (env('SESSION_COOKIE') ?: 'hkm_session');
        $incoming      = $request->cookie($cookieName);
        $hadCookie     = $incoming !== null && $incoming !== '';
        $path          = (string) (env('SESSION_COOKIE_PATH') ?: '/');
        $domain        = (env('SESSION_COOKIE_DOMAIN') ?: null) ?: null;

        // Cookie driver: the session state lives in the cookie itself, so bind the
        // client context (for optional fingerprint validation) and feed the incoming
        // cookie value to the handler before loading.
        $cookieHandler = $this->cookieHandler($session);
        if ($cookieHandler !== null) {
            $cookieHandler->bindClient($request->userAgent(), $request->ip());
            $cookieHandler->prime($incoming);
        }

        $session->start($incoming);

        $response = $next($request);

        // Remember the page for the post-login redirect — BEFORE the persistence
        // check below, so the write is part of this request's save.
        $this->rememberPreviousPage($request, $session, $response);

        // Lazy persistence: a fresh visitor that never used the session leaves no
        // server file (and, for the cookie driver, no payload) and gets no cookie —
        // stateless traffic (APIs, bots) stays clean.
        if (!$session->shouldPersist()) {
            return $response;
        }

        $session->save();

        // Cookie driver carries the serialized payload; server drivers carry the id.
        $value = $cookieHandler !== null ? $cookieHandler->outgoing() : $session->id();

        // The cookie driver could not emit a payload (e.g. it exceeded the size
        // ceiling). If the browser already holds a session cookie it would keep a
        // now-stale session alive, so actively expire it rather than leave it.
        if ($value === null) {
            return $hadCookie ? $response->withoutCookie($cookieName, $path, $domain) : $response;
        }

        return $response->withCookie(
            name:     $cookieName,
            value:    $value,
            maxAge:   (int) (env('SESSION_LIFETIME') ?: 7200),
            path:     $path,
            domain:   $domain,
            secure:   $this->secure($request),
            httpOnly: true,
            sameSite: (string) (env('SESSION_SAMESITE') ?: 'Lax'),
        );
    }

    /**
     * Record the current page into the session so a later login can send the
     * user straight back to it. Only real, successful page views qualify:
     *   - GET requests with a 2xx response,
     *   - an HTML navigation OR a Pageflow page object (X-Pageflow response
     *     header — SPA visits count as page views too),
     *   - never auth/registration/OAuth/API paths (the DESTINATION of the login
     *     flow, not a place to return to) and never static-asset-looking paths.
     *
     * Only the RELATIVE path + query is stored (never scheme/host); the login
     * flows re-validate the value before redirecting (relative path only), so
     * the session can never become an open redirect.
     */
    private function rememberPreviousPage(Request $request, SessionPort $session, Response $response): void
    {
        if ($request->method() !== 'GET') {
            return;
        }

        $path = $request->path();
        if ($this->isExemptPath($path) || $this->looksLikeAsset($path)) {
            return;
        }

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            return;
        }

        // A page view is an HTML navigation or a Pageflow page object; other
        // JSON/file responses on GET routes are data fetches, not pages.
        $contentType = (string) $response->headers->get('Content-Type');
        if (!str_contains($contentType, 'text/html') && !$response->headers->has('X-Pageflow')) {
            return;
        }

        // Path + query only, rebuilt from the parsed params (never the raw URI,
        // which can throw on a malformed Host header).
        $url   = $path;
        $query = http_build_query($request->queryAll());
        if ($query !== '') {
            $url .= '?' . $query;
        }

        if (\strlen($url) <= self::PREVIOUS_URL_MAX && $session->get(self::PREVIOUS_URL) !== $url) {
            $session->put(self::PREVIOUS_URL, $url);
        }
    }

    private function isExemptPath(string $path): bool
    {
        foreach ([...self::PREVIOUS_URL_EXEMPT, ...$this->configuredExemptions()] as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return true;
            }
        }

        return false;
    }

    /** A path with a file extension is an asset (/favicon.ico, /app.css), not a page. */
    private function looksLikeAsset(string $path): bool
    {
        return (bool) preg_match('/\.[a-z0-9]{2,5}$/i', $path);
    }

    /** @return list<string> */
    private function configuredExemptions(): array
    {
        $raw = (string) (env('SESSION_PREVIOUS_EXEMPT') ?? '');
        if ($raw === '') {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (string $p): string => '/' . ltrim(trim($p), '/'),
            explode(',', $raw),
        ), static fn (string $p): bool => $p !== '/'));
    }

    /**
     * Whether to flag the session cookie Secure. SESSION_SECURE forces it on/off;
     * unset (or "auto") follows the request scheme so dev over plain HTTP still works.
     */
    private function secure(Request $request): bool
    {
        $flag = strtolower((string) (env('SESSION_SECURE') ?: 'auto'));

        return match ($flag) {
            'true', '1', 'on', 'yes'   => true,
            'false', '0', 'off', 'no'  => false,
            default                    => $request->isSecure(),
        };
    }

    /** The cookie-backed handler when the cookie driver is active, else null. */
    private function cookieHandler(SessionPort $session): ?CookieBackedHandler
    {
        if (!$session instanceof Store) {
            return null;
        }

        $handler = $session->handler();

        return $handler instanceof CookieBackedHandler ? $handler : null;
    }
}
