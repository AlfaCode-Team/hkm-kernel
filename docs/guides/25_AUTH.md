# Auth Plugin — Authentication (tokens + sessions)

> AI reference for `Plugins\Auth\` (solves `auth.identity`).
> Issues credentials (JWT, personal access tokens) and provides the
> SecurityLayer verifiers the kernel runs before any module loads. Pairs with
> [09_SECURITY](09_SECURITY.md), [24_USER](24_USER.md) (verifies credentials),
> [26_OAUTH2](26_OAUTH2.md) (OAuth2 access tokens are the same JWTs this layer
> verifies).

---

## WHAT IT DOES

The kernel ships **no** token validator — Auth fills the intended "AuthModule
layer" slot. It splits cleanly:

- **Issuance** lives in `AuthService` (exposed via `AuthServiceContract`): mint
  JWTs, create/revoke personal access tokens (PATs), establish/tear down web
  sessions, hash/verify passwords.
- **Verification** lives in `SecurityLayer` classes a project wires into
  `Kernel::withSecurity([...])`; the SecurityGateway runs them before any module
  loads (deny = zero module cost).

```
requires: ["database.management", "crypto.services", "user.management"]
exposes:  ["Plugins\Auth\API\Contracts\AuthServiceContract"]
```
Control-plane tables (`personal_access_tokens`) are pinned to the **central**
connection. The session login flow verifies credentials via `UserServiceContract`.

---

## SECURITY LAYERS (wired in the project bootstrap)

### `JwtAuthLayer` — stateless Bearer JWT
```php
new JwtAuthLayer(
    secret:   $hsSecretOrPublicKeyPem,   // HS secret, or PEM PUBLIC key for RS/ES/PS
    algo:     'RS256',                   // single pinned algo — never trust the token's `alg`
    issuer:   env('JWT_ISSUER'),         // when set, `iss` MUST match
    audience: env('JWT_AUDIENCE'),       // when set, `aud` MUST contain it (list-aware, hash_equals)
    leeway:   60,                        // clock-skew tolerance for exp/iat/nbf
    revocations: $cachePort,             // optional jti deny-list
);
```
- No `Authorization` header → **allow as guest** (public routes keep working).
- Valid Bearer → `Identity` from `sub`/`tnt`/`roles`/`permissions`.
- Malformed / expired / wrong iss|aud / **revoked `jti`** → `deny(401)`.
- Revocation deny-list **fails OPEN** on a cache outage (token is otherwise valid).

### `PersonalAccessTokenLayer` — DB-backed `Bearer <id>.<secret>`
Hashes (`sha256`) and matches against `personal_access_tokens`; **enforces
`expires_at`** (expired = absent), loads the token's `abilities` into
`Identity.permissions`, and stamps `last_used_at`. Empty `tenantId` (unscoped /
central) — consistent with the JWT layer.

JWT/JOSE verification is the ONLY auth the kernel delegates here; everything else
(firewall, rate-limit, CSRF) is kernel-native.

---

## PUBLISHED CONTRACT — `AuthServiceContract`

| Method | Notes |
|---|---|
| `issueJwt(userId, claims, ttl): string` | adds `iat/nbf/exp/jti`, plus `iss/aud` when configured. Asymmetric algos sign with the **private key** (`JWT_PRIVATE_KEY[_FILE]`), optional `kid` |
| `revokeJwt(jti, ttl): void` | deny-lists a `jti` via `CachePort` (key `auth:jwt:revoked:<jti>`) so a token dies before expiry |
| `createPersonalAccessToken(userId, name, abilities, ttl): {id, token}` | plaintext returned ONCE; only the hash is stored; optional abilities + expiry |
| `revokePersonalAccessToken(id): void` | |
| `tokensFor(userId): list<TokenDTO>` | lists a user's PATs (newest first), **no secret material**. GDA replacement for the old `HasApiTokens::tokens()` |
| `guard(Request): Guard` | read-only projection over the request `Identity` — replaces the old `AuthManager`/named guards (see below) |
| `startSession(SessionPort, userId, roles, permissions, tenantId): void` | rotates session id (fixation defence), stores identity |
| `endSession(SessionPort): void` | invalidate + rotate |
| `hashPassword / verifyPassword` | bcrypt/argon2 via `HashingPort`, timing-safe |

---

## GUARD — READ-ONLY IDENTITY PROJECTION (replaces `AuthManager`)

There is no guard/driver factory. The SecurityGateway chain
(`JwtAuthLayer` → `PersonalAccessTokenLayer` → `SessionAuthStage`) already
resolved WHO authenticated and by WHICH credential. `Plugins\Auth\API\Guard` is a
stateless, allocation-cheap projection over the request `Identity`:

| Method | Meaning |
|---|---|
| `check()` / `guest()` | authenticated? |
| `id()` / `tenantId()` | user id / tenant ('' = central) |
| `via()` | `'jwt' \| 'api_key' \| 'session' \| 'none'` — the "named guard", derived not chosen |
| `viaToken()` / `viaSession()` | Bearer credential vs stateful session |
| `hasRole()` / `hasPermission()` | RBAC |
| `hasScope(s)` | token scope — matches a bare permission OR OAuth2's `scope:<s>` namespaced form |

Controllers get it via the `Project\Http\Controllers\Concerns\InteractsWithAuth`
concern: `$this->guard()`, `$this->identity()`, `$this->authId()`,
`$this->tokenCan('write')`. Works even without the Auth module loaded (it reads
the kernel `Identity`).

---

## AUTHMANAGER — NAMED GUARDS + PROVIDERS (config-driven)

For multi-guard apps (session web + token API + jwt), `AuthManager` manages named
**guards** and user **providers** from `config/auth.php`. GDA-native rework of the
old `__DEV__` AuthManager — no global `auth.` alias, no `kernel()`/`config()`
reach-ins, and the kernel `Identity` stays the principal (guards resolve an
`AuthUserProxy` that **emits** an `Identity`).

```php
$manager->guard();            // default guard (config defaults.guard)
$manager->guard('api')->user();   // ?Authenticatable (AuthUserProxy)
$manager->guard('jwt')->identity(); // kernel Identity
$manager->provider('users');  // a named UserProvider (ModelUserProvider)
```

| Piece | Role |
|---|---|
| `AuthManager` | request-scoped registry; `guard($name)`, `user()`, `check()`, `id()`, `provider($name)`. Bind `setRequest($request)` per use (Request is not container-bound) |
| `UserProvider` / `ModelUserProvider` | resolves users from a store. Default `users` provider is ModelUserProvider over `UserServiceContract` (no ORM). `retrieveByCredentials` does the FULL timing-safe verify (the store hides the hash) |
| `AuthUserProxy` | lightweight current-user; carries id/username/email + security context; `identity(): Identity`. NOT the principal |
| `GuardDriver` (`Infrastructure/Auth/Drivers/*`) | `session` (session store), `jwt`/`token` (rehydrate the SecurityGateway verdict by tokenType), `request` (credential-agnostic) |

**Driver "scan":** `AuthManager::drivers()` filesystem-scans
`Infrastructure/Auth/Drivers/*.php` for `GuardDriver` implementations, keyed by
`driverName()`, **once per process, cached** (boot-time — a deliberate,
documented exception to the GDA no-runtime-discovery rule; never on the hot path).

Controllers: `Project\Http\Controllers\Concerns\InteractsWithAuthManager` →
`$this->auth('api')->user()`, `$this->authUser()`. A route using it must declare
`"requires": ["auth.identity"]`. Config lives in `config/auth.php` (project copy
wins), read via `auth_config()`.

---

## HIERARCHICAL SCOPE INHERITANCE

Scopes/abilities are colon-hierarchical: a held scope satisfies every descendant.
`ScopeInheritance::satisfies($held, $required)` powers `Guard::hasScope()`,
`AuthUserProxy::tokenCan()` and `TokenDTO::can()`.

```php
Guard::actingAs('u1', ['admin'])->hasScope('admin:users:write'); // true (ancestor)
Guard::actingAs('u1', ['reports'])->hasScope('billing');         // false
// '*' grants everything; 'scope:'-namespaced (OAuth2) and bare (PAT) both match;
// non-colon-boundary prefixes never match ('adm' ≠ 'admin').
```

---

## PERSONAL ACCESS TOKENS — self-service (`/auth/tokens`)

First-party user API keys (`Bearer <id>.<secret>`), owner-scoped to the caller's
Identity. Backed by `AuthServiceContract` (hash-only storage). NOT OAuth clients,
NOT used by session login.

| Route | Action |
|---|---|
| `GET /auth/tokens` | list my tokens (no secrets) |
| `POST /auth/tokens` | mint (plaintext returned ONCE) |
| `DELETE /auth/tokens/{id}` | revoke one of MY tokens (else 404) |

`AuthServiceContract`: `createPersonalAccessToken`, `revokePersonalAccessToken`,
`tokensFor(userId): list<TokenDTO>`. `PersonalAccessTokenFactory` +
`PersonalAccessTokenResult` mint the one-time result. `AuthUserProxy` exposes
HasApiTokens (`tokens()/token()/tokenCan()/createToken()`).

---

## REFRESH TOKENS — revocable first-party sessions (`/auth/refresh`)

Relocated from Tenancy (authentication ≠ tenancy). `RefreshTokenServiceContract`:
`issue/rotate/revoke/revokeAllForUser`. One-time-use rotation with rotation-family
reuse detection (replay/race → burn the family → 401). Only the SHA-256 is stored;
the raw token is returned once. Table `refresh_tokens` (central, `family_id`).

**Tenant-agnostic:** `tenantId` rides through as a scope hint for the paired
access token's `tnt` claim but is NEVER re-verified on refresh — tenant seat checks
live in the Tenancy `/ajx/tenants/{id}/select` flow.

- `POST /auth/refresh` `{token}` → new access JWT + rotated refresh token (401 on invalid/reuse).
- `POST /auth/refresh/logout` `{token}` → revoke a single session.

## TRANSIENT TOKEN — first-party SPA (`/auth/token/refresh`)

`POST /auth/token/refresh` (auth-filtered). A session-authenticated SPA mints a
short-lived (900s) JWT carrying the session identity's real roles/permissions —
the scoped replacement for Passport's blanket transient token. A Bearer/PAT caller
(non-session) is refused.

## PASSWORD RESET — `PasswordBroker`

CachePort-backed, enumeration-safe. `sendResetLink(email)` mints a one-time hashed
token (throttled); `validateToken`; `reset(email, token, newPassword)` sets the
password (via `UserServiceContract::resetPassword`, which also clears remember
tokens) and burns the token. Statuses: `RESET_LINK_SENT` / `PASSWORD_RESET` /
`INVALID_USER` / `INVALID_TOKEN` / `THROTTLED`.

---

## SESSION AUTH (web + AJAX)

The session is opened at `after.load` (`StartSessionStage`, priority 20) — AFTER
the SecurityGateway — so session auth CANNOT be a SecurityLayer. Instead
`SessionAuthStage` is an `after.load` hook at **priority 22** (after session
start, before the route `auth` filter):

- A request already carrying a token-derived `Identity` is left untouched (token
  wins).
- An anonymous request with a logged-in session gets a `tokenType: 'session'`
  Identity rebuilt from the session.
- The same `auth` route filter then protects **both** token and session callers.

**The session Identity is bound into BOTH the request AND the request-scoped
container.** `OnDemandLoader` binds `Identity::class` at `LoadStage` from the
PRE-auth (guest) request — which runs *before* this `after.load` stage. So
`SessionAuthStage::attach()` rebinds `Identity::class` into `$request->container()`
too, not just the request. Without that rebind the `auth` route filter would pass
(it reads the request) but every **service** — which injects `Identity` from the
container — would still see a guest, so service-layer permission checks
(`requirePermission()`, `isGuest()`) would wrongly fail. Token auth is unaffected:
it attaches its Identity in the SecurityGateway (before `LoadStage`), so the
container already holds the right one. Any stage that *elevates* an Identity
mid-pipeline (adds roles/permissions) must follow the same rule — rebind the
container, not only the request.

Endpoints (`SessionAuthController`): `POST /auth/login` (verifies via User module,
then `startSession`), `POST /auth/logout`, `GET /auth/me`. CSRF is the kernel's
`CsrfTokenLayer` (these routes are outside `/api`).

### Post-login redirect ("previous page")

The Session plugin's `StartSessionStage` records the last eligible page view
(GET + 2xx, HTML navigation OR a Pageflow page object via the `X-Pageflow`
response header; auth/OAuth/API/asset paths exempt, extend with
`SESSION_PREVIOUS_EXEMPT`) under **`StartSessionStage::PREVIOUS_URL`** — the
SINGLE source of truth for the key (value `auth.previous_url`; no duplicate
const anywhere). On successful `POST /auth/login`, first match wins:

1. an explicit `redirectTo` on the login request (query or body),
2. the recorded previous page — PULLED one-time, so a fulfilled intent never
   goes stale,
3. `/`.

Browser form POSTs get a real 302; AJAX/SPA callers get `redirectTo` in the
JSON payload (alongside `user`) and navigate client-side. BOTH candidates pass
the same open-redirect guard (`safeRedirect()`): relative `/…` paths only —
`//host`, `/\` tricks and absolute URLs are rejected. SocialAuth's web
callback consumes the same key (falls back to `SOCIAL_AUTH_SUCCESS_REDIRECT`).

### Display identity (username / email / fullName / avatarUrl)

`Identity` carries best-effort display fields. `AuthService` fills
username/email from the central user store at issuance when the caller didn't
supply them (`displayIdentity()` → `UserServiceContract::find(id, false,
isAuth: true)` — `isAuth` skips the self-or-permission check, since at
issuance the request Identity is still guest). They ride as OIDC claims
(`preferred_username`, `email`, `name`) on JWTs — rebuilt statelessly by
`JwtAuthLayer` — and as session keys (`SESSION_USERNAME/EMAIL/NAME/AVATAR`)
for session logins/recaller resurrection. `name` (first + last) lives in the
TENANT `user_profiles` table, so only tenant-aware flows (tenant selection)
mint it. The `users` constructor dep is a **LAZY closure** (`fn():
UserServiceContract`): an eager `make()` recurses AuthService → UserService →
MembershipService → AuthService until `max_execution_time`.

### Remember-me (recaller cookie)

`POST /auth/login` with `remember=true` issues an encrypted `remember_web`
cookie holding a `userId|token` **recaller** (`Plugins\Auth\Domain\ValueObjects\Recaller`
— a flat pipe string; NEVER unserialized). When a later request has no live
session, `SessionAuthStage::fromRecaller()`:

1. reads + decrypts the cookie (via the essential `CookieJar`);
2. resolves the user by the token's SHA-256 hash (`UserServiceContract::findByRememberToken`),
   rejecting a mismatched owner id or a forged/stale token;
3. re-opens the session (`startSession`, rotating the id) and attaches a
   `tokenType: 'session'` Identity;
4. **rotates** the token + cookie (`cycleRememberToken`) so a stolen cookie is a
   single-use window.

Logout clears the stored token (`clearRememberToken`) and expires the cookie, so
outstanding recallers die immediately. The `remember_token` column + index live
on the central `users` table. Backed by `UserServiceContract`:
`findByRememberToken(token)`, `cycleRememberToken(userId): plaintext`,
`clearRememberToken(userId)`.

---

## CLI

- `auth:tokens:prune [--dry] [--watch=SECONDS]` — delete expired PATs (cron or a
  supervised loop for no-cron environments).

---

## CONFIG (env)

`JWT_SECRET`, `JWT_ALGO` (default HS256), `JWT_ISSUER`, `JWT_AUDIENCE`,
`JWT_PRIVATE_KEY` / `JWT_PRIVATE_KEY_FILE` (asymmetric signing — file form keeps
keys off the process env), `JWT_KID`, `AUTH_PAT_TABLE`,
`AUTH_REFRESH_TTL` (refresh-token lifetime, default 30d),
`AUTH_REFRESH_ACCESS_TTL` (paired access-JWT lifetime, default 900s),
`AUTH_GUARD` / `AUTH_PROVIDER` (AuthManager defaults). Guard/provider maps live in
`config/auth.php` (read via `auth_config()`).

---

## RULES

```
✓ Verification = SecurityLayers (gateway); issuance = AuthService. Never mix.
✓ Pin a SINGLE algo in JwtAuthLayer — never let the token's `alg` choose the verifier.
✓ Asymmetric (RS/ES/PS) for any deployment where verifiers must not hold the signing secret.
✓ PATs: store only the hash, return plaintext once, enforce expires_at, load abilities as permissions.
✓ Session login AFTER credential verification; rotate the session id (fixation defence).
✓ Guard is a projection over the request Identity — never a stateful driver/AuthManager, never a global.
✓ Remember-me: store only the token HASH, rotate on every use, match the cookie's owner id, clear on logout.
✓ Refresh tokens live in Auth, not Tenancy. One-time-use rotation; a replay/race burns the whole family.
✓ Scopes are hierarchical — an ancestor satisfies its descendants; never do a bare string-equality scope check.
✗ Re-checking tenant seat membership on refresh — refresh is tenant-agnostic; the seat check is at tenant-SELECT.
✗ A SecurityLayer that THROWS — always return a SecurityVerdict.
✗ Unserializing a recaller/cookie value — the recaller is a flat `id|token` string (object-injection safe).
✗ Trusting a `tnt` claim as authorization — it is a routing hint; authz keys on (userId, tenantId, role/permission).
✗ getenv() for JWT_* — use env() (see 11_PROJECT).
```
