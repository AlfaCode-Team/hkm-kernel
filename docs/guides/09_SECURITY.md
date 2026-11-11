# HKM Kernel — Security Layer

> The SecurityGateway runs **before any module loads**. A denied request never touches
> module code, costs microseconds, and returns immediately.

---

## Security Architecture

The kernel's `SecurityGateway` runs an ordered list of `SecurityLayerContract` layers
(configured via `Kernel::withSecurity([...])`). Layers run in declaration order and the
**first `deny()` short-circuits** the rest — nothing further runs.

**What the kernel ships:** two opt-in layers — `WafLayer` (application firewall, see
[WafLayer](#waflayer--the-application-firewall) below) and `CsrfTokenLayer` (stateless
HMAC-signed CSRF). Everything else is contributed by plugins:

- **Authentication** (JWT / personal-access-token verification) is added by the **Auth
  plugin** — `JwtAuthLayer` and `PersonalAccessTokenLayer`, which you place in
  `withSecurity([...])`. The kernel intentionally ships no token validator.
- **Rate limiting** and **IP filtering** are NOT gateway layers — they are **SecurityFilters
  plugin** route filters (`throttle` → `ApiRateLimitStage`, `shield` → `ShieldStage`) that run
  inside the HTTP pipeline once a route opts in.

```
Request arrives
    │
    ▼
SecurityGateway (always resident — runs your withSecurity([...]) layers in order)
    │
    ├── WafLayer.check()              ← kernel, opt-in: malformed / traversal / probe / injection
    │         DENY → 403 immediately ──────────────────────────────────────►
    │
    ├── CsrfTokenLayer.check()        ← kernel: stateless HMAC CSRF (state-changing verbs)
    │         DENY → 403 immediately ──────────────────────────────────────►
    │
    └── [Auth plugin] JwtAuthLayer / PersonalAccessTokenLayer.check()  ← token verify
              DENY → 401 immediately ──────────────────────────────────────►
              │
              CLEARED → Identity attached to Request
                              │
                              ▼
                       after.security hooks  ← plugin-registered stages
                              │
                              ▼   (later, after.load) route filters: throttle / shield / auth
                       Rest of pipeline
```

**Order matters:** layers run in the order you list them in `withSecurity([...])`, and the
first deny wins — so put the cheapest / most common denials first.

---

## SecurityLayerContract

```php
interface SecurityLayerContract
{
    /**
     * Check the request. Return allow or deny.
     * On allow: optionally attach or augment Identity on the request.
     * On deny:  return immediately — no further layers run.
     */
    public function check(Request $request): SecurityVerdict;
}
```

---

## SecurityVerdict

```php
final class SecurityVerdict
{
    // Allow — request proceeds to the next security layer or pipeline
    public static function allow(Request $request): self;

    // Deny — pipeline stops here, HTTP error returned immediately
    public static function deny(int $statusCode, string $reason): self;

    public function isDenied(): bool;
    public function isAllowed(): bool;
    public function identity(): ?Identity;
    public function statusCode(): int;    // 401 | 403 | 429
    public function reason(): string;
}
```

---

## Identity — The Security Passport

```php
final readonly class Identity
{
    public function __construct(
        public readonly string $userId,
        public readonly string $tenantId,
        public readonly array  $roles,        // ['admin', 'user']
        public readonly array  $permissions,  // ['invoice:create', 'invoice:view-all']
        public readonly string $tokenType,    // 'jwt' | 'api_key' | 'session'
    ) {}

    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roles, true);
    }

    public function hasPermission(string $perm): bool
    {
        return in_array($perm, $this->permissions, true);
    }

    public function isGuest(): bool
    {
        return empty($this->userId);
    }
}
```

**Identity is set once by the SecurityGateway and never modified downstream.**
All service-level authorization uses `$this->identity->hasPermission()` or `->hasRole()`.

---

## Security capabilities — where each lives

| Capability | Where it lives | How it runs |
|---|---|---|
| **Application firewall** | Kernel — `WafLayer` | Gateway layer (below), opt-in |
| **CSRF** | Kernel — `CsrfTokenLayer` | Gateway layer (below) |
| **Authentication** (JWT / PAT / session) | **Auth plugin** — `JwtAuthLayer`, `PersonalAccessTokenLayer`, `SessionAuthStage` | Gateway layers you add in `withSecurity([...])`; session via `after.load` stage |
| **Rate limiting** | **SecurityFilters plugin** — `ApiRateLimitStage` | `throttle:MAX,MINUTES` route filter (sliding window via `CachePort`) |
| **IP allow/deny + shielding** | **SecurityFilters plugin** — `ShieldStage` | `shield` route filter |
| **CORS + secure headers** | **SecurityFilters plugin** — `CorsStage`, `SecurityHeadersStage` | `after.security` global hooks |

> The kernel ships `WafLayer` and `CsrfTokenLayer`, nothing else. There is no kernel IP
> allow/deny layer or `RateLimiterLayer` — rate limiting and IP filtering are opt-in route
> filters from the SecurityFilters plugin, and token authentication comes from the Auth plugin.
> `WafLayer`'s optional IP ban is not rate limiting: it counts only requests that already
> matched an attack signature.

### WafLayer — the application firewall

Opt-in. List it **first** in `withSecurity([...])`: it is cheap, and a scanner should be
turned away before anything else spends time on it. A refused request costs a few regex
passes and loads no module.

```php
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Layers\WafLayer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Waf\WafMode;

->withSecurity([
    new WafLayer(
        mode:            WafMode::Monitor,                   // start here; switch to Block after reading the log
        exemptPaths:     ['/api/webhooks'],                  // never inspected (segment-aware prefixes)
        ruleExemptPaths: ['injection' => ['/admin/posts']],  // switch ONE rule off where rich text is posted
        trustedIps:      ['10.0.0.0/8', '2001:db8::/32'],    // exact IPs or CIDR, bypass the WAF
        banAfter:        0,                                  // strikes before an IP ban; 0 = never (default)
        banWindow:       600,                                // seconds strikes are counted over
        banSeconds:      900,                                // ban length
    ),
    new CsrfTokenLayer(/* … */),
])
```

**Default rules** (`WafLayer::defaultRules()`), run cheapest first; the first match decides:

| Rule id | Catches | Signatures |
|---|---|---|
| `malformed` | Request shapes no client produces | `control-byte`, `invalid-utf8`, `nul-byte`, `smuggling` (Content-Length + Transfer-Encoding), `url-too-long` (8192), `too-many-inputs` (2000, counted recursively — PHP's `max_input_vars` does not cover JSON) |
| `traversal` | Directory walks and stream wrappers | `dot-dot-segment` in the path (decoded up to 3×), `climb-to-system-dir` and `stream-wrapper` (`php://`, `phar://`, …) in query values |
| `probe` | Scanner paths | `dotfile` (`.env`, `.git`, …), `wordpress`, `db-admin`, `vendor` (`vendor/phpunit`), `php-probe`, `cgi`, `server-info`, `device`, `backup` (`.sql`/`.bak`/`.old`/`.orig`/`.swp`), `manifest` (`/composer.lock`, …) |
| `injection` | Attack payloads | `sqli-union`, `sqli-schema`, `sqli-timing`, `sqli-tautology`, `sqli-stacked`, `sqli-file`, `sqli-comment`, `xss-script`, `xss-handler`, `xss-tag`, `xss-scheme`, `log4shell`, `php-tag`, `php-call`, and `unscannable` (a value that exhausted a PCRE limit) — on the path, query values, `User-Agent` and `Referer`; the body only with `inspectBody: true`, first `maxScanBytes` (64 KB) of each value |

Tune a rule by constructing it yourself:

```php
new WafLayer(rules: [
    new MalformedRequestRule(maxInputs: 5000),
    new PathTraversalRule(),
    new ScannerProbeRule(allow: ['cgi'], extra: ['/old-admin']),
    new InjectionSignatureRule(inspectBody: true, disable: ['xss-tag'], maxScanBytes: 65536),
    new MyProjectRule(),                         // any WafRuleContract
]),
```

**Behaviour you should know before turning it on:**

- **Monitor first.** `WafMode::Monitor` logs `waf.would_block` (notice) and lets the request
  through. `WafMode::Block` (the default) refuses with 403 and logs `waf.blocked` (warning).
  The log context carries `rule`, `signature`, `location`, a clipped `sample`, `ip`, `method`,
  `path`, `userAgent` and `requestId`.
- **The 403 never says which rule fired.** Its message is `Request blocked.` — telling a
  scanner which signature it tripped tells it what to change.
- **It fails open.** A rule or cache that throws is logged as `waf.error` and the request is
  let through. A firewall whose cache outage takes the site down has become the attack.
- **Ports.** `WafLayer` implements `PortAwareLayerContract`: at materialize the kernel hands
  it the CoreContainer, and it resolves the bound `CachePort` (bans) and `LoggerPort` lazily —
  the same singletons every module gets, not a second adapter. Explicit `cache:` / `logger:`
  constructor arguments take precedence. With no `LoggerPort` bound it blocks silently.
- **Bans are off by default.** Carrier-grade NAT puts thousands of mobile users behind one
  address; one scanner among them would lock them all out. Enable `banAfter` only where
  clients have their own addresses. Bans need a `CachePort` and never apply in Monitor mode.
  IPv6 clients are banned by their **/64**, since one subscriber holds the whole prefix.
  The ban keys on `Request::ip()` — behind a CDN that is the CDN edge unless the edge
  server restores the real client address (Edge's real-IP setup does); without that, a ban
  locks out everyone routed through that edge.
- **Trusted networks are parsed strictly.** An entry that does not parse (`10.0.0.0/`,
  `10.0.0.0/abc`, `/33`) is ignored — it never widens to `/0`.
- **Cost.** A clean GET with three query values and a browser User-Agent: ~18 µs. Every rule
  is linear on hostile input — 8 KB of `<`, quotes, `../` or `/*` costs at most ~0.15 ms —
  and each injection signature is gated on a byte or word it requires, so ordinary values
  skip almost all of them. With `inspectBody`, budget roughly 10 ns per scanned body byte.
- **It is defence in depth.** SQL injection is prevented by binding values through
  `DatabasePort`, XSS by escaping output. The signatures stop automated payloads early and
  make them visible; they guarantee nothing. Volumetric abuse belongs at the edge.

A custom rule is stateless and must not throw (a throw fails open, so it never blocks):

```php
final class BlockOldApiRule implements WafRuleContract
{
    public function id(): string { return 'old-api'; }

    public function inspect(Request $request): ?WafMatch
    {
        return str_starts_with($request->path(), '/api/v0/')
            ? WafMatch::of($this->id(), 'retired-version', 'path', $request->path())
            : null;
    }
}
```

### CsrfTokenLayer

```php
// Stateless HMAC-signed CSRF token (the WordPress-nonce model) — NOT plain
// double-submit. No token is stored and NO cookie value is trusted as the
// token; a valid token cannot be forged without APP_KEY, so cookie injection
// (sibling sub-domain / MITM) cannot bypass it.
//
//   token = tick . "." . hmac(APP_KEY, tick|binding|action)
//
// Safe methods (GET/HEAD/OPTIONS) + exemptPaths bypass; an empty APP_KEY
// fail-closes (denies). lifetime is in SECONDS (default 43200 = 12h).
// Mint with CsrfTokenLayer::make(), verify out-of-band with ::valid().
//
// Full guide + framework-level usage: docs/guides/21_CSRF.md
new CsrfTokenLayer(
    headerName:  'X-CSRF-Token',
    formField:   '_csrf_token',
    bindCookie:  'hkm_session',   // pin to the HttpOnly session cookie ('' = unbound)
    lifetime:    43200,           // seconds
    exemptPaths: ['/api'],        // machine-to-machine endpoints with their own auth
);
```

### Token verification is a plugin's job

**The kernel ships no JWT, API-key or session token validator, deliberately.** It
defines `SecurityLayerContract` and runs whatever layers a project passes to
`withSecurity([...])`; an auth plugin supplies the verifiers. Which layers exist,
what claims they read and how they are configured is that plugin's documentation,
not the kernel's.

What the kernel guarantees regardless of the plugin: a layer **never throws** (it
returns a verdict), a missing credential means **anonymous rather than denied**
(public routes keep working), and a denial costs **zero module loading**.

### Tenant context on the Identity (`tenantId`)

`Identity.tenantId` is the only multi-tenancy the KERNEL knows about: an
immutable string it carries and hands to whatever runs next. The kernel does not
resolve tenants, own a registry, or know what a tenant database is — an auth
plugin populates the field, and a tenancy plugin acts on it.

Three rules bind every consumer of that field, and they are kernel-level
guarantees rather than any one plugin's behaviour:

- **The tenant id is a HINT, not authority.** Whatever set it — a signed claim, a
  cookie, a host label — authorization still keys on
  `(userId, tenantId, role/permission)`, re-checked against the store that owns
  memberships. A signature proves the value survived transit unmodified; it does
  not prove the seat still exists.
- **Empty is not "central access".** An absent tenant means *unresolved*, not
  *privileged*. Any component that treats a missing tenant as permission to read
  a shared/central store must say so explicitly and pin that connection itself.
- **A per-request rebind goes in the request-scoped container.** A plugin that
  rebinds `DatabasePort` for a tenant binds it into the `ModuleContainer`
  (discarded on `reset()`), never `CoreContainer` and never a static. Under
  OpenSwoole a leaked binding means one tenant's request served from another
  tenant's database.

How a tenant is identified, routed, provisioned and revoked belongs to the
Tenancy, Auth and User plugins, and is documented in their repositories.

---

## Writing a Custom Security Layer

```php
final class RequireVerifiedEmailLayer implements SecurityLayerContract
{
    public function __construct(
        private readonly CachePort $cache,
    ) {}

    public function check(Request $request): SecurityVerdict
    {
        $identity = $request->identity();

        // If no identity yet (guest or public route), allow through
        if (!$identity || $identity->isGuest()) {
            return SecurityVerdict::allow($request);
        }

        // Check email verification from cache (fast path)
        $verified = $this->cache->get("email_verified:{$identity->userId}");

        if ($verified === null) {
            // Cache miss — check is done at service layer for first request
            return SecurityVerdict::allow($request);
        }

        if (!$verified) {
            return SecurityVerdict::deny(403, 'Email address is not verified');
        }

        return SecurityVerdict::allow($request);
    }
}
```

Register layers in the bootstrap (order = run order; first deny wins):
```php
$kernel->withSecurity([
    new CsrfTokenLayer(headerName: 'X-CSRF-Token', /* … */),  // kernel — CSRF
    new JwtAuthLayer(/* … */),                                // Auth plugin — token verify
    new PersonalAccessTokenLayer(/* … */),                    // Auth plugin — PATs
    new RequireVerifiedEmailLayer($cache),                    // ← your custom layer last
]);
```

Rate limiting and IP filtering are not added here — a route opts into them with the
SecurityFilters `throttle` / `shield` route filters (see the [SecurityFilters plugin](https://github.com/AlfaCode-Team/hkm-plugin-security-filters)).

---

## JWT Token Lifecycle

```
Login
  │
  ▼
AuthService.login(LoginDTO)
  ├── Verify password (bcrypt.verify — constant time)
  ├── Issue access token  (JWT, 1 hour TTL, signed with HS256)
  ├── Issue refresh token (JWT, 7 days TTL, stored in Redis)
  └── Return {access_token, refresh_token, expires_in}

Subsequent requests
  │
  ▼
JwtAuthLayer.check()  (Auth plugin)
  ├── Extract Bearer token from Authorization header
  ├── Decode header + payload (base64url)
  ├── Verify signature with hash_equals() — TIMING SAFE
  ├── Check exp claim
  └── Build Identity from claims → attach to Request

Refresh
  │
  ▼
AuthService.refresh(RefreshTokenDTO)
  ├── Look up refresh token in Redis
  ├── Check if already rotated (reuse detection)
  ├── Mark current token as rotated
  ├── Issue new token pair
  └── Return new {access_token, refresh_token}
```

---

## Rate Limit Configuration

```php
// config/security.php
return [
    'limits' => [
        'global_ip'  => ['max' => 1000, 'window' => 60, 'strategy' => 'sliding_window'],
        'per_user'   => ['max' => 300,  'window' => 60, 'strategy' => 'sliding_window'],
        'routes'     => [
            'POST /api/auth/login'  => ['max' => 5,  'window' => 60],
            'POST /api/auth/forgot' => ['max' => 3,  'window' => 3600],
            'POST /api/payments'    => ['max' => 30, 'window' => 60],
        ],
    ],
    'public_routes' => [
        'POST /api/auth/login',
        'POST /api/auth/register',
        'GET  /api/health',
    ],
];
```

---

## Service-Level Authorization Pattern

```php
// After SecurityGateway clears the request, authorization happens in the Service layer.
// SecurityGateway: WHO is this? (authentication)
// Service layer:   WHAT can they do? (authorization)

public function delete(string $invoiceId): void
{
    $invoice = $this->repository->find($invoiceId);

    // RBAC: does the user have the permission at all?
    if (!$this->identity->hasPermission('invoice:delete')) {
        throw new ServiceException('invoice.delete.unauthorized');
    }

    // ABAC: does the user own this specific resource?
    if ($invoice->clientId()->value() !== $this->identity->userId
        && !$this->identity->hasPermission('invoice:delete-any')) {
        throw new ServiceException('invoice.delete.unauthorized');
    }

    $this->repository->softDelete($invoiceId);
}
```

---

## Security code rules

When writing or reviewing security code:

- **DO** place custom SecurityLayer implementations in the Project layer or a plugin (e.g. Auth)
- **DO** use `hash_equals()` for all token and signature comparisons — never `===` or `==`
- **DO** order `withSecurity([...])` cheapest-deny-first; the first `deny()` short-circuits the rest
- **DO** attach `Identity` to the request in the authenticating layer (Auth's `JwtAuthLayer`), not elsewhere
- **DO** check authorization in the Service layer, not in the SecurityGateway layers
- **DON'T** access `DatabasePort` from a SecurityLayer — use `CachePort` only
- **DON'T** put authorization (what they can do) in the SecurityGateway — that's authentication (who they are)
- **DON'T** throw exceptions from SecurityLayer — return `SecurityVerdict::deny()`
- **DON'T** use `===` for timing-sensitive comparisons (HMAC, token comparison)
- **DON'T** log passwords, tokens, or secrets anywhere in the security pipeline
