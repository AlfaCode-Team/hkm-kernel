<?php

declare(strict_types=1);

namespace Plugins\SecurityFilters\Infrastructure\Http\Stages;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\Contracts\HttpStageContract;

/**
 * Require an authenticated Identity on SPECIFIC route paths only.
 *
 * The auth SecurityLayer (JwtAuthLayer / PersonalAccessTokenLayer) runs for
 * every request and attaches an Identity when a valid token is present, but it
 * lets anonymous requests through so public routes keep working. This stage is
 * where you say "these paths must be authenticated" — everything else stays
 * open.
 *
 * Configure with the AUTH_PROTECTED_PATHS env var, comma-separated. Each pattern
 * is matched against the request path and supports:
 *
 *   /profile/settings   exact match
 *   /admin/*            prefix match (everything under /admin/)
 *   /api/*\/edit         '*' wildcard segment (matches one path segment)
 *
 * Example:
 *   AUTH_PROTECTED_PATHS="/profile/settings,/account,/admin/*"
 *
 * Unauthenticated hits to a protected path get 401; matching is path-only so it
 * runs at `after.security`, before any module loads (cheap reject).
 */
final class RequireAuthStage implements HttpStageContract
{
    public function handle(Request $request, callable $next): Response
    {
        // Enforce when EITHER the path is in AUTH_PROTECTED_PATHS (global hook
        // mode) OR the matched route declared the "auth" filter (declarative
        // mode — module.json / proj.json "filters": ["auth"]).
        $declared = in_array('auth', (array) $request->attribute('active_filters'), true);

        if (!$declared && !$this->isProtected($request->path())) {
            return $next($request);
        }

        $identity = $request->identity();
        if ($identity === null || $identity->isGuest()) {
            // A browser navigating to a protected PAGE should land on the login
            // page (the natural "page" for an auth-required error), not a raw
            // JSON body. We redirect ONLY genuine page navigations:
            //   • a Pageflow SPA navigation — carries the X-Pageflow header and,
            //     though XHR (expectsJson() true), the client follows a 302 as a
            //     client-side visit; OR
            //   • a full browser page load — advertises `Accept: text/html` and
            //     does NOT expect JSON.
            // Everything else (API/fetch/XHR, or a header-less/programmatic
            // request) keeps the machine-readable 401. The requested path rides
            // along as ?redirectTo so login can bounce back (Auth guards it —
            // same-origin/relative only).
            $isPageflow = (string) ($request->header('X-Pageflow') ?? '') !== '';
            $wantsHtml  = str_contains(strtolower((string) ($request->header('Accept') ?? '')), 'text/html');

            if ($isPageflow || ($wantsHtml && !$request->expectsJson())) {
                // Rebuild path + query from the query bag (never $request->uri(),
                // which requires a host and throws on host-less requests).
                $params = $request->queryAll();
                $query  = $params !== [] ? http_build_query($params) : '';
                $target = $request->path() . ($query !== '' ? '?' . $query : '');

                return Response::redirect('/login?redirectTo=' . rawurlencode($target));
            }

            return Response::unauthorized('Authentication is required to access this resource.');
        }

        return $next($request);
    }

    private function isProtected(string $path): bool
    {
        $raw = (string) (env('AUTH_PROTECTED_PATHS') ?: '');
        if ($raw === '') {
            return false;
        }

        $path = '/' . trim($path, '/');

        foreach (explode(',', $raw) as $pattern) {
            $pattern = trim($pattern);
            if ($pattern === '') {
                continue;
            }
            if ($this->matches($pattern, $path)) {
                return true;
            }
        }

        return false;
    }

    private function matches(string $pattern, string $path): bool
    {
        $pattern = '/' . trim($pattern, '/');

        // Fast path: exact match.
        if ($pattern === $path) {
            return true;
        }

        // Trailing "/*" means "this prefix and anything below it".
        if (str_ends_with($pattern, '/*')) {
            $prefix = substr($pattern, 0, -2);
            return $path === $prefix || str_starts_with($path, $prefix . '/');
        }

        // Otherwise treat "*" as a single-segment wildcard via regex.
        if (str_contains($pattern, '*')) {
            $regex = '#^' . str_replace('\*', '[^/]+', preg_quote($pattern, '#')) . '$#';
            return (bool) preg_match($regex, $path);
        }

        return false;
    }
}
