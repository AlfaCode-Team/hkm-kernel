# HKM Kernel — Security Layer

> The SecurityGateway runs **before any module loads**. A denied request never touches
> module code, costs microseconds, and returns immediately.

---

## Security Architecture

The kernel's `SecurityGateway` runs an ordered list of `SecurityLayerContract` layers
(configured via `Kernel::withSecurity([...])`). Layers run in declaration order and the
**first `deny()` short-circuits** the rest — nothing further runs.

**What the kernel ships:** exactly one layer — `CsrfTokenLayer` (stateless HMAC-signed CSRF).
Everything else is contributed by plugins:

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
| **CSRF** | Kernel — `CsrfTokenLayer` | Gateway layer (below) |
| **Authentication** (JWT / PAT / session) | **Auth plugin** — `JwtAuthLayer`, `PersonalAccessTokenLayer`, `SessionAuthStage` | Gateway layers you add in `withSecurity([...])`; session via `after.load` stage |
| **Rate limiting** | **SecurityFilters plugin** — `ApiRateLimitStage` | `throttle:MAX,MINUTES` route filter (sliding window via `CachePort`) |
| **IP allow/deny + shielding** | **SecurityFilters plugin** — `ShieldStage` | `shield` route filter |
| **CORS + secure headers** | **SecurityFilters plugin** — `CorsStage`, `SecurityHeadersStage` | `after.security` global hooks |

> The kernel deliberately ships **only** `CsrfTokenLayer`. There is no kernel `FirewallLayer`
> or `RateLimiterLayer` — rate limiting and IP filtering are opt-in route filters from the
> SecurityFilters plugin, and token authentication comes from the Auth plugin.

### CsrfTokenLayer (the one kernel layer)

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

### Auth plugin layers — `JwtAuthLayer` / `PersonalAccessTokenLayer`

```php
// Provided by Plugins\Auth (the kernel ships NO JWT code). You add them to
// withSecurity([...]) alongside CsrfTokenLayer.
//   JwtAuthLayer                — verifies a Bearer JWT (iss/aud/exp, jti deny-list),
//                                 builds Identity from claims (incl. the `tnt` tenant claim).
//   PersonalAccessTokenLayer    — verifies long-lived personal access tokens.
// Session-based auth is a separate after.load stage (SessionAuthStage), not a gateway layer.
// All signature/token comparisons are timing-safe (hash_equals()).
```

### Tenant context on the Identity (`tnt` claim — multi-tenant control plane)

`Identity.tenantId` carries the authenticated tenant for database-per-tenant
routing. `Plugins\Auth\Security\JwtAuthLayer` reads it from the signed **`tnt`**
claim (legacy `tenant` accepted for BC) and defaults it to **`''` (empty)**:

```php
$tenant = (string) ($claims['tnt'] ?? $claims['tenant'] ?? '');
$identity = new Identity(userId: $claims['sub'], tenantId: $tenant, /* … */);
```

- **Empty tenant claim ≠ central access.** `AuthService::issueJwt()` mints NO
  tenant at login — but `TenantContextStage` routes STRICTLY: with no tenant
  claim, the remembered cookie hint and then the Host identifier must still
  resolve one, or the request 404s (no unscoped passthrough). Login/picker/public
  pages therefore live on a host that is itself assigned to a tenant;
  control-plane reads pin the central connection explicitly.
- **Non-empty tenant** is routed to its isolated database by
  `Plugins\Tenancy`'s `TenantContextStage` (hooked `after.load`), which rebinds
  `DatabasePort` in the request container. Mint a tenant-scoped token ONLY after
  the user selects a tenant and membership is verified against the central
  `user_tenants` table; re-check membership each request so a revoked seat loses
  access before the token expires.
- **Control-plane plugins pin to central.** `Plugins\User` (the global `users`
  identity table) and `Plugins\Auth` (`personal_access_tokens`) resolve the
  `DatabaseConnectionManagerContract` **default** connection, NOT the per-request
  (tenant-rebound) `DatabasePort` — so identity I/O never lands in a tenant DB.
  Because the `tnt` claim is signed it cannot be forged, but it is still a hint,
  not authority: authorization keys on `(userId, tenantId, role/permission)`.

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
SecurityFilters `throttle` / `shield` filters (see `20_FIRST_PARTY_PLUGINS.md`).

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
