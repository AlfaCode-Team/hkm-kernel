<?php

declare(strict_types=1);

namespace Plugins\SecurityFilters\Infrastructure\Http\Stages;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\Contracts\HttpStageContract;

/**
 * Single always-on security stage for the SecurityFilters plugin (GDA rewrite of
 * the 0.3 Cors + SecureHeaders filters, merged into ONE global hook).
 *
 * Registered once at after.security. Because a stage is an onion, this class does
 * BOTH the early work (CORS preflight short-circuit, before $next) and the late
 * work (decorating the outgoing response with CORS + OWASP headers, after $next):
 *
 *   1. BEFORE $next — CORS: an OPTIONS preflight is answered with 204 + the CORS
 *      headers and never reaches auth/modules; a disallowed origin short-circuits
 *      the preflight and otherwise passes through without CORS headers.
 *   2. AFTER $next  — stamp Access-Control-* (for allowed cross-origin requests)
 *      AND the OWASP security headers (HSTS over HTTPS, CSP when configured) onto
 *      every response, regardless of which module produced it.
 *
 * Zero-dependency: no Symfony/CodeIgniter CORS service. Configuration is env-read
 * so the router stays in module.json:
 *
 *   CORS_ALLOWED_ORIGINS   "*" | "https://a.com,https://b.com"
 *   CORS_ALLOWED_METHODS   default "GET,POST,PUT,PATCH,DELETE,OPTIONS"
 *   CORS_ALLOWED_HEADERS   default "Content-Type,Authorization,X-Requested-With"
 *   CORS_EXPOSED_HEADERS   default "" (none)
 *   CORS_MAX_AGE           preflight cache seconds, default 0
 *   CORS_ALLOW_CREDENTIALS "true"|"false", default false
 *   HSTS_MAX_AGE           default 31536000 (emitted only over HTTPS)
 *   CONTENT_SECURITY_POLICY  omitted when empty so projects opt in
 *
 * When credentials are allowed the wildcard origin is echoed back as the exact
 * request Origin (the spec forbids "*" with credentials).
 */
final class SecurityHeadersStage implements HttpStageContract
{
    /** @var array<string, string> */
    private const SECURE_HEADERS = [
        'X-Frame-Options'                    => 'SAMEORIGIN',
        'X-Content-Type-Options'             => 'nosniff',
        'X-Permitted-Cross-Domain-Policies'  => 'none',
        'Referrer-Policy'                    => 'strict-origin-when-cross-origin',
        'Cross-Origin-Opener-Policy'         => 'same-origin',
    ];

    public function handle(Request $request, callable $next): Response
    {
        $origin      = $request->header('origin');
        $isCors      = $origin !== null && $origin !== '';
        $allowOrigin = $isCors ? $this->resolveAllowedOrigin($origin) : null;

        // ── CORS preflight — answer before auth/modules ──────────────────────
        if ($isCors && $request->isMethod('OPTIONS')) {
            if ($allowOrigin === null) {
                return Response::empty(204); // disallowed origin — no CORS headers
            }
            $headers = $this->corsHeaders($allowOrigin);
            $headers['Access-Control-Allow-Methods'] = (string) (env('CORS_ALLOWED_METHODS')
                ?: 'GET,POST,PUT,PATCH,DELETE,OPTIONS');
            $headers['Access-Control-Allow-Headers'] = $request->header('access-control-request-headers')
                ?? (string) (env('CORS_ALLOWED_HEADERS') ?: 'Content-Type,Authorization,X-Requested-With');
            $maxAge = (int) (env('CORS_MAX_AGE') ?: 0);
            if ($maxAge > 0) {
                $headers['Access-Control-Max-Age'] = (string) $maxAge;
            }
            return Response::empty(204)->withHeaders($headers);
        }

        // ── Actual request — run the pipeline, then decorate the response ─────
        $response = $next($request);
        $headers  = $this->secureHeaders($request);

        if ($isCors && $allowOrigin !== null) {
            $headers += $this->corsHeaders($allowOrigin);
            $exposed = trim((string) (env('CORS_EXPOSED_HEADERS') ?: ''));
            if ($exposed !== '') {
                $headers['Access-Control-Expose-Headers'] = $exposed;
            }
        }

        return $response->withHeaders($headers);
    }

    /** @return array<string, string> */
    private function secureHeaders(Request $request): array
    {
        $headers = self::SECURE_HEADERS;

        if ($request->isSecure()) {
            $maxAge = (int) (env('HSTS_MAX_AGE') ?: 31536000);
            $headers['Strict-Transport-Security'] = 'max-age=' . $maxAge . '; includeSubDomains';
        }

        $csp = trim((string) (env('CONTENT_SECURITY_POLICY') ?: ''));
        if ($csp !== '') {
            $headers['Content-Security-Policy'] = $csp;
        }

        return $headers;
    }

    /** @return array<string, string> */
    private function corsHeaders(string $allowOrigin): array
    {
        $headers = [
            'Access-Control-Allow-Origin' => $allowOrigin,
            'Vary'                        => 'Origin',
        ];
        if ($this->allowsCredentials()) {
            $headers['Access-Control-Allow-Credentials'] = 'true';
        }
        return $headers;
    }

    private function resolveAllowedOrigin(string $origin): ?string
    {
        $configured = trim((string) (env('CORS_ALLOWED_ORIGINS') ?: '*'));

        if ($configured === '*') {
            // Cannot send "*" together with credentials — echo the origin.
            return $this->allowsCredentials() ? $origin : '*';
        }

        foreach (explode(',', $configured) as $candidate) {
            if (strcasecmp(trim($candidate), $origin) === 0) {
                return $origin;
            }
        }
        return null;
    }

    private function allowsCredentials(): bool
    {
        return filter_var(env('CORS_ALLOW_CREDENTIALS') ?: 'false', FILTER_VALIDATE_BOOLEAN);
    }
}
