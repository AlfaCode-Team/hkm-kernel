<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Security\Layers;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\ClockPort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\SystemClock;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Contracts\SecurityLayerContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\SecurityVerdict;

/**
 * CsrfTokenLayer — stateless CSRF protection using HMAC-signed tokens.
 *
 * This is the WordPress-nonce model, NOT the plain double-submit cookie pattern.
 * Nothing is stored (no server-side table) and — crucially — the token is NEVER
 * read from or trusted in a cookie. The token is a self-verifying HMAC:
 *
 *     token = tick . "." . hex( HMAC_SHA256( SECRET, tick . "|" . binding . "|" . action ) )
 *
 *   - SECRET  : a server-only key (APP_KEY) the attacker never possesses.
 *   - tick    : a coarse time window → tokens expire (default lifetime 12h,
 *               with a one-tick grace window, exactly like WordPress).
 *   - binding : an opaque per-client value read from a cookie the attacker
 *               cannot read (the HttpOnly session cookie). Optional — when no
 *               binding cookie is present the token is secret-only (still
 *               unforgeable, just not pinned to one client).
 *   - action  : optional scope (e.g. "delete-post:42"); '' for a global token.
 *
 * Why this is stronger than double-submit:
 *   Plain double-submit only checks "submitted value == cookie value", so an
 *   attacker who can WRITE a cookie (sibling sub-domain, or a MITM on plain
 *   HTTP) can plant a matching cookie/token pair and pass the check. Here there
 *   is no cookie to trust: a valid token cannot be produced without SECRET, so
 *   cookie injection buys the attacker nothing.
 *
 * Delivery is the project's job: render a token into forms / a meta tag via
 * {@see issue()} (or {@see mint()}), then echo it back in the configured header
 * or form field on unsafe requests.
 *
 * NEVER throws — always returns a SecurityVerdict (the layer contract).
 */
final class CsrfTokenLayer implements SecurityLayerContract
{
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    private readonly string $secret;

    /**
     * @param string|null  $secret        HMAC key; defaults to env('APP_KEY'). Empty disables verification-safety (see below).
     * @param string       $headerName    request header carrying the token
     * @param string       $formField     fallback body/query field carrying the token
     * @param string       $bindCookie    cookie whose value pins the token to one client (HttpOnly session cookie)
     * @param int          $lifetime      token validity in seconds (a one-window grace is added on top)
     * @param list<string> $exemptPaths   path prefixes that bypass the check (e.g. ['/api/webhooks'])
     * @param list<string> $exemptMethods extra methods to treat as safe (case-insensitive)
     */
    public function __construct(
        ?string $secret = null,
        private readonly string $headerName    = 'X-CSRF-Token',
        private readonly string $formField     = '_csrf_token',
        private readonly string $bindCookie    = '',
        private readonly int    $lifetime      = 43200,
        private readonly array  $exemptPaths   = [],
        private readonly array  $exemptMethods = [],
        /**
         * Source of "now". Defaults to the real clock, so behaviour is unchanged.
         * Tests inject a frozen clock to exercise token expiry without sleeping
         * past a 12-hour lifetime — which is why this expiry logic was
         * effectively untestable before.
         */
        private readonly ?ClockPort $clock = null,
    ) {
        $this->secret = $secret ?? (string) (env('APP_KEY') ?: '');
    }

    public function check(Request $request): SecurityVerdict
    {
        $method = strtoupper($request->method());

        // Safe methods never require a CSRF token.
        if (in_array($method, self::SAFE_METHODS, true)) {
            return SecurityVerdict::allow($request);
        }
        foreach ($this->exemptMethods as $exempt) {
            if (strtoupper($exempt) === $method) {
                return SecurityVerdict::allow($request);
            }
        }

        // Exempt paths (e.g. machine-to-machine webhooks with their own signature).
        $path = $request->path();
        foreach ($this->exemptPaths as $prefix) {
            if ($prefix !== '' && str_starts_with($path, $prefix)) {
                return SecurityVerdict::allow($request);
            }
        }

        // A missing secret means the platform is misconfigured. Fail closed —
        // an attacker must never benefit from an unset APP_KEY.
        if ($this->secret === '') {
            return SecurityVerdict::deny(403, 'CSRF secret not configured.');
        }

        $submitted = $request->header($this->headerName)
            ?? (is_string($v = $request->input($this->formField)) ? $v : null);

        if ($submitted === null || $submitted === '') {
            return SecurityVerdict::deny(403, 'CSRF token missing.');
        }

        if (!$this->verify($submitted, $this->binding($request))) {
            return SecurityVerdict::deny(403, 'CSRF token invalid or expired.');
        }

        return SecurityVerdict::allow($request);
    }

    /**
     * Mint a token for the CURRENT request's binding. Call this when rendering a
     * form / meta tag so the client can echo it back on the next unsafe request.
     */
    public function issue(Request $request, string $action = ''): string
    {
        return self::make($this->secret, $this->binding($request), $this->lifetime, $action, $this->clock);
    }

    // ─── public static API (for controllers / views that mint & check tokens) ──

    /**
     * Mint a fresh token for an arbitrary secret + binding. Use this from a
     * controller/view helper that does NOT hold the layer instance.
     *
     *   $token = CsrfTokenLayer::make(env('APP_KEY'), $sessionCookieValue);
     */
    public static function make(string $secret, string $binding = '', int $lifetime = 43200, string $action = '', ?ClockPort $clock = null): string
    {
        return self::build($secret, self::tickFor($lifetime, $clock), $binding, $action);
    }

    /**
     * Verify a token out-of-band (e.g. a manual AJAX check). The SecurityGateway
     * already runs check() for every unsafe request, so this is only for code
     * that wants to validate a token itself without denying the request.
     */
    public static function valid(string $secret, string $token, string $binding = '', int $lifetime = 43200, ?ClockPort $clock = null): bool
    {
        if ($secret === '' || $token === '') {
            return false;
        }

        $dot = strpos($token, '.');
        if ($dot === false) {
            return false;
        }

        $tick = (int) substr($token, 0, $dot);
        if ($tick <= 0) {
            return false;
        }

        $now = self::tickFor($lifetime, $clock);
        // Accept this tick or the immediately previous one; reject anything else
        // (expired, or a future tick that should not yet exist).
        if ($tick !== $now && $tick !== $now - 1) {
            return false;
        }

        // Recompute the canonical token for the claimed tick and compare in full
        // (timing-safe) — the embedded tick alone proves nothing without the sig.
        $expected = self::build($secret, $tick, $binding, self::actionFrom($token));

        return hash_equals($expected, $token);
    }

    // ─── internals ───────────────────────────────────────────────────────────

    private function verify(string $token, string $binding): bool
    {
        return self::valid($this->secret, $token, $binding, $this->lifetime, $this->clock);
    }
 
    /** token = tick . "." . hex(HMAC(secret, tick|binding|action)). Action rides after the sig for re-derivation. */
    private static function build(string $secret, int $tick, string $binding, string $action): string
    {
        $sig = hash_hmac('sha256', $tick . '|' . $binding . '|' . $action, $secret);

        // A global ('') token carries no action; a scoped token appends it so
        // valid() can re-derive the scope it was signed with.
        return $action === ''
            ? $tick . '.' . $sig
            : $tick . '.' . $sig . '.' . $action;
    }

    /** Extract the optional action scope appended after the signature. */
    private static function actionFrom(string $token): string
    {
        // Format: tick "." sig ["." action]. Split into at most 3 parts.
        $parts = explode('.', $token, 3);

        return $parts[2] ?? '';
    }

    /** WordPress-style half-life tick: two overlapping windows per lifetime. */
    private static function tickFor(int $lifetime, ?ClockPort $clock = null): int
    {
        $half = max(1, intdiv($lifetime, 2));
        $now  = ($clock ?? new SystemClock())->timestamp();

        return (int) ceil($now / $half);
    }

    /**
     * The per-client binding value: the configured cookie's value, read from the
     * raw Cookie header. Returns '' (secret-only token) when unconfigured/absent.
     */
    private function binding(Request $request): string
    {
        if ($this->bindCookie === '') {
            return '';
        }

        return $this->extractCookie($request, $this->bindCookie) ?? '';
    }

    /**
     * Parse the `Cookie` request header and extract a single cookie value.
     * Returns null if the cookie is absent or malformed.
     */
    private function extractCookie(Request $request, string $name): ?string
    {
        $header = $request->header('Cookie');
        if ($header === null || $header === '') {
            return null;
        }

        foreach (explode(';', $header) as $pair) {
            $pair = ltrim($pair);
            $eq   = strpos($pair, '=');
            if ($eq === false) {
                continue;
            }
            if (substr($pair, 0, $eq) === $name) {
                return urldecode(substr($pair, $eq + 1));
            }
        }

        return null;
    }
}
