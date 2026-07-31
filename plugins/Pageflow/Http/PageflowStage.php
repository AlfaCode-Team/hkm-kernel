<?php

declare(strict_types=1);

namespace Plugins\Pageflow\Http;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ValidationException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\Contracts\HttpStageContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\SessionPort;
use Plugins\Pageflow\API\Contracts\PageflowSharerContract;

/**
 * Single Pageflow pipeline stage — the plugin's whole HTTP protocol in one onion
 * hook (GDA rewrite that merges the former Version / Validation / Precognition /
 * Share stages into ONE class).
 *
 * Registered once at after.load, so the request container (and the Pageflow
 * responder) exist. Ordered internally:
 *
 *   BEFORE $next
 *     1. Version guard — a Pageflow XHR GET carrying a stale X-Pageflow-Version
 *        gets 409 + X-Pageflow-Location so the client full-reloads onto the new
 *        bundle. (Runs a hair later than the old after.security position; a
 *        stale-asset GET is rare, so the extra load work is negligible.)
 *     2. Precognition flag — a "validate only, no side effects" request is marked
 *        via request attributes so any layer can refuse writes (defence in depth;
 *        the controller short-circuit via pageflow_precognition() stays the real
 *        guard). No outer DB transaction is opened — Services own their own.
 *     3. Shared props — populate the responder once (do_action('pageflow_share'))
 *        when the route has it in scope and the project bound a sharer.
 *
 *   AROUND $next
 *     4. Validation — translate a kernel ValidationException into the Pageflow
 *        error envelope: 422 { "errors" } for a precognitive request; otherwise
 *        flash to the session and 303-redirect back so the origin page re-renders
 *        through the FULL pipeline with the `errors` shared prop. No session →
 *        degrade to 422. Non-Pageflow requests re-throw for the kernel ErrorStage.
 */
final class PageflowStage implements HttpStageContract
{
    /** Session key the errors are flashed under; read back by the errors sharer. */
    public const ERROR_FLASH_KEY = 'pageflow_errors';

    public function handle(Request $request, callable $next): Response
    {
        // 1. Stale-asset guard — cheap 409 reject before the controller runs.
        if ($this->isPageflow($request) && strtoupper($request->method()) === 'GET') {
            $clientVersion  = (string) ($request->header('X-Pageflow-Version') ?? '');
            $currentVersion = (string) (env('PAGEFLOW_VERSION') ?: '');
            if ($currentVersion !== '' && $clientVersion !== $currentVersion) {
                return Response::json([], 409, [
                    'X-Pageflow-Location' => $this->fullUrl($request),
                ]);
            }
        }

        // 2. Flag precognition requests so every layer can refuse side effects.
        if ($this->isPrecognitive($request)) {
            $rollback = (bool) (env('PAGEFLOW_PRECOGNITION_ROLLBACK') ?: false);
            $request  = $request
                ->withAttribute('precognition', true)
                ->withAttribute('precognition_fields', $this->precognitionFields($request))
                ->withAttribute('precognition_rollback', $rollback);
        }

        // 3. Populate shared props once modules are loaded.
        $container = $request->container();
        if ($container !== null
            && $container->has(PageflowResponder::class)
            && $container->has(PageflowSharerContract::class)
        ) {
            /** @var PageflowResponder $responder */
            $responder = $container->make(PageflowResponder::class);
            /** @var PageflowSharerContract $sharer */
            $sharer = $container->make(PageflowSharerContract::class);
            $sharer->share($request, $responder);
        }

        // 4. Wrap execution to translate validation errors into Pageflow's shape.
        try {
            return $next($request);
        } catch (ValidationException $e) {
            if (!$this->isPageflow($request)) {
                throw $e; // let the kernel ErrorStage render it (non-SPA client)
            }

            $errors = $this->flatten($e->errors);

            if ($this->isPrecognitive($request)) {
                return Response::json(['errors' => $errors], 422, [
                    'X-Pageflow'   => 'true',
                    'Precognition' => 'true',
                    'Vary'         => 'X-Pageflow, Precognition',
                ]);
            }

            $session = $this->session($request);
            if ($session !== null) {
                $bag = (string) ($request->header('X-Pageflow-Error-Bag') ?? '');
                $session->flash(self::ERROR_FLASH_KEY, $bag !== '' ? [$bag => $errors] : $errors);

                return Response::redirect($this->backUrl($request), 303);
            }

            // No session plugin — the client's useForm won't auto-populate, but a
            // manual onError handler still receives these. Session is recommended.
            return Response::json(['errors' => $errors], 422, ['X-Pageflow' => 'true']);
        }
    }

    private function isPageflow(Request $request): bool
    {
        return strtolower((string) ($request->header('X-Pageflow') ?? '')) === 'true';
    }

    private function isPrecognitive(Request $request): bool
    {
        return strtolower((string) ($request->header('Precognition') ?? '')) === 'true';
    }

    private function session(Request $request): ?SessionPort
    {
        $container = $request->container();
        if ($container !== null && $container->has(SessionPort::class)) {
            /** @var SessionPort $session */
            $session = $container->make(SessionPort::class);
            return $session;
        }
        return null;
    }

    private function fullUrl(Request $request): string
    {
        $path  = $request->path();
        $query = http_build_query($request->queryAll());
        return $query === '' ? $path : $path . '?' . $query;
    }

    /** @return list<string> */
    private function precognitionFields(Request $request): array
    {
        $raw = (string) ($request->header('Precognition-Validate-Only') ?? '');
        if ($raw === '') {
            return [];
        }
        return array_values(array_filter(
            array_map('trim', explode(',', $raw)),
            static fn(string $s): bool => $s !== '',
        ));
    }

    /**
     * Prefer the referer; fall back to the client-declared URL, then the path.
     *
     * SECURITY: every candidate is reduced to a same-origin path (+query) — the
     * scheme/host are stripped — so the 303 Location can never point off-site.
     */
    private function backUrl(Request $request): string
    {
        $referer = $this->pathOnly((string) ($request->header('referer') ?? ''));
        if ($referer !== '') {
            return $referer;
        }

        $headerUrl = $this->pathOnly((string) ($request->header('X-Pageflow-Url') ?? ''));
        if ($headerUrl !== '') {
            return $headerUrl;
        }

        return $request->path();
    }

    /** Reduce any URL to a same-origin "/path?query" (never a scheme/host). */
    private function pathOnly(string $url): string
    {
        if ($url === '') {
            return '';
        }

        $path = parse_url($url, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return '';
        }

        // Guard against protocol-relative ("//evil.com/x") and backslash tricks.
        $path = '/' . ltrim(str_replace('\\', '/', $path), '/');

        $query = parse_url($url, PHP_URL_QUERY);
        return is_string($query) && $query !== '' ? $path . '?' . $query : $path;
    }

    /**
     * Normalise ValidationException errors (string|string[]) to field => message.
     *
     * @param array<string, string|string[]> $errors
     * @return array<string, string>
     */
    private function flatten(array $errors): array
    {
        $out = [];
        foreach ($errors as $field => $message) {
            $out[(string) $field] = is_array($message)
                ? (string) ($message[0] ?? '')
                : (string) $message;
        }
        return $out;
    }
}
