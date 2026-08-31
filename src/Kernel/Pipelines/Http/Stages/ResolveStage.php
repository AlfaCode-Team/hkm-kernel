<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\Stages;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\{Request, Response};
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\Contracts\HttpStageContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\RouteMatcher;
use AlfacodeTeam\PhpServicePlatform\Kernel\Routing\RouteIndex;

/**
 * ResolveStage — turns method+path into the route entry the rest of the pipeline
 * runs on, or ends the request with a 404 before any module is loaded.
 *
 * Three optional behaviours, all OFF or inert by default so an existing app
 * resolves exactly as it did:
 *
 *   - `405 Method Not Allowed` (+ Allow header) instead of 404 when the path
 *     exists under a different method. Opt-in, because a 405 confirms that a
 *     path exists and so hands a scanner free reconnaissance; a 404 does not.
 *   - a trailing-slash redirect, under the `redirect` policy.
 *   - a face restriction: a route may declare `faces: ["admin"]` and is then
 *     invisible on any other face. The kernel stays domain-agnostic — it reads
 *     the plain `route_face` request attribute and never imports the project's
 *     DomainContext. A mismatch 404s rather than 403s: a route the caller may
 *     not reach on this host should not advertise that it exists elsewhere.
 */
final class ResolveStage implements HttpStageContract
{
    public function __construct(
        private readonly RouteMatcher $matcher,
        private readonly bool $methodNotAllowed = false,
    ) {}

    public function handle(Request $request, callable $next): Response
    {
        $method = $request->method();
        $path   = $request->path();
        // Expanding the host into candidate keys costs more than the match
        // itself, so an application that groups nothing never pays for it.
        $domains = $this->matcher->hasDomainGroups() ? self::domains($request) : [];

        $match = $this->matcher->match($method, $path, $domains);

        if ($match === null) {
            return $this->miss($method, $path, $domains);
        }

        if (!$this->faceAllows($request, $match['entry'])) {
            return Response::notFound();
        }

        // One clone, not three: chaining withAttribute() would build two
        // intermediate requests — each a deep clone of all seven parameter bags
        // — that nothing ever reads.
        return $next($request->withAttributes([
            'route_entry'    => $match['entry'],
            'route_params'   => $match['params'],
            'target_service' => $match['entry']['solves'],
        ]));
    }

    /**
     * The domain-group keys this request may match, most specific first.
     *
     * The host comes from the `route_host` attribute when an entry point set one
     * — that is DomainContext->host, which DomainResolver already matched against
     * projects.json. Otherwise it falls back to `Request::host()`, the raw Host
     * header. Prefer the attribute: the header is client-controlled and no
     * trusted-host allowlist filters it here, so on the fallback path a caller
     * can choose which domain group serves it.
     *
     * @return list<string>
     */
    private static function domains(Request $request): array
    {
        $host = $request->attribute('route_host');

        return RouteIndex::hostCandidates(
            is_string($host) && $host !== '' ? $host : $request->host(),
        );
    }

    /**
     * No route matched: redirect to the canonical form, 405, or 404.
     *
     * @param list<string> $domains
     */
    private function miss(string $method, string $path, array $domains): Response
    {
        $canonical = $this->matcher->canonicalPath($method, $path, $domains);
        if ($canonical !== null) {
            return Response::permanentRedirect($canonical);
        }

        if ($this->methodNotAllowed) {
            $allowed = $this->matcher->allowedMethods($path, $domains);

            if ($allowed !== []) {
                return Response::json([
                    'error' => [
                        'code'    => 'method_not_allowed',
                        'message' => "The {$method} method is not supported for this route.",
                    ],
                ], 405)->withHeader('Allow', implode(', ', $allowed));
            }
        }

        return Response::notFound();
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function faceAllows(Request $request, array $entry): bool
    {
        $faces = $entry['faces'] ?? [];

        if (!is_array($faces) || $faces === []) {
            return true;   // unrestricted — every route, unless it opted in
        }

        $face = $request->attribute('route_face');

        if (!is_string($face) || $face === '') {
            // Nothing declared the current face (CLI-driven tests, an entry point
            // that does not resolve a domain). Restricting on unknown information
            // would silently 404 the route everywhere.
            return true;
        }

        return in_array(strtolower($face), $faces, true);
    }
}
