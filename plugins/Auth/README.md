# Auth — Authentication (`solves: auth.identity`)

The **single home for authentication** on the AlfacodeTeam PhpServicePlatform. It
decides *who* a caller is and gives you ergonomic ways to work with that identity.
It does **not** do authorization policy (that's your service layer) and it is
**not** the multi-tenant control plane (that's `Plugins\Tenancy`).

> 📄 A full typeset walkthrough ships alongside this file: [`AUTH_GUIDE.pdf`](AUTH_GUIDE.pdf).
> Deep-dive reference: see the Auth design notes in this README below.

## The one split to remember

| | Where it lives | Classes |
|---|---|---|
| **Issuance** — mint credentials | service layer | `AuthServiceContract`, `RefreshTokenServiceContract` |
| **Verification** — check a credential | SecurityGateway (before any module loads) | `JwtAuthLayer`, `PersonalAccessTokenLayer`, `SessionAuthStage` |

The principal produced by verification is the kernel's immutable `Identity`,
carried on the request. Everything else here (Guard, AuthManager, AuthUserProxy)
is a **projection** over that `Identity` — none of them replace it.

## The five authentication methods

| Method | Credential | Verified / issued by |
|---|---|---|
| JWT (Bearer) | `Authorization: Bearer <jwt>` | `JwtAuthLayer` / `AuthService::issueJwt` |
| Personal access token | `Authorization: Bearer <id>.<secret>` | `PersonalAccessTokenLayer` / `AuthService::createPersonalAccessToken` |
| Session (web/AJAX) | session cookie + `remember_web` cookie | `SessionAuthStage` / `AuthService::startSession` |
| Refresh token | opaque token in POST body | `RefreshTokenService::rotate` / `::issue` |
| Transient token | session → short JWT | `TransientTokenController` |

---

## 1. The principal: `Identity`

```php
final readonly class Identity {
    public string $userId;      // '' for a guest
    public string $tenantId;    // '' = central / unscoped
    public array  $roles;       // list<string>
    public array  $permissions; // list<string> (PAT abilities / OAuth scopes)
    public string $tokenType;   // 'jwt' | 'api_key' | 'session' | 'none'
    public string $username;    // display identity — best-effort, '' when unknown
    public string $email;
    public string $fullName;    // tenant user_profiles; tenant-scoped credentials only
    public ?string $avatarUrl;
    public function hasRole(string $r): bool;
    public function hasPermission(string $p): bool;   // honours '*'
    public function isGuest(): bool;
}
```

Read it with `$request->identity()`. Do **not** invent another principal type.

## 2. Verification — SecurityGateway layers

Wire the layers in the kernel builder; they run before any module loads and
**never throw** (they return a `SecurityVerdict`).

```php
->withSecurity([
    new FirewallLayer(...), new RateLimiterLayer(...), new CsrfTokenLayer(...),
    new JwtAuthLayer(
        secret: env('JWT_SECRET'), algo: env('JWT_ALGO', 'HS256'),
        issuer: env('JWT_ISSUER'), audience: env('JWT_AUDIENCE'),
        revocations: $cachePort,          // jti deny-list
    ),
    new PersonalAccessTokenLayer($databasePort),   // Bearer <id>.<secret>
]);
```

- **`JwtAuthLayer`** — signature, `iss`/`aud`, expiry (+leeway), `jti` deny-list
  via `CachePort`. Pin a *single* algorithm; never let the token's `alg` choose.
- **`PersonalAccessTokenLayer`** — hashes `<id>.<secret>`, enforces `expires_at`,
  loads abilities into `Identity.permissions`.
- No header → guest. Bad credential → `deny(401)`.

Session auth **can't** be a SecurityLayer (the session opens at `after.load`), so
`SessionAuthStage` runs at `after.load` priority 22 and attaches a
`tokenType: 'session'` Identity — the same `auth` route filter then covers token
*and* session callers. A token Identity already present is left untouched.

## 3. Issuance — `AuthServiceContract`

| Method | Notes |
|---|---|
| `issueJwt(userId, claims, ttl): string` | adds `iat/nbf/exp/jti` + `iss/aud`; asymmetric signs with the private key |
| `revokeJwt(jti, ttl)` | deny-lists a `jti` (key `auth:jwt:revoked:<jti>`) |
| `createPersonalAccessToken(userId, name, abilities, ttl)` | `{id, token}`; plaintext once, hash stored |
| `revokePersonalAccessToken(id)` | |
| `tokensFor(userId): list<TokenDTO>` | a user's PATs, no secrets |
| `guard(Request): Guard` | read-only projection |
| `startSession(session, userId, roles, perms, tenantId)` | rotates the session id, stores identity |
| `endSession(session)` | invalidate + rotate |
| `hashPassword / verifyPassword` | bcrypt/argon2, timing-safe |

```php
$jwt = $auth->issueJwt('user-123', [
    'roles' => ['admin'], 'permissions' => ['invoice:create'], 'tnt' => 'tenant-9',
], ttlSeconds: 3600);
$auth->revokeJwt($jti, 3600);   // kill it before exp
```

## 4. Guard + hierarchical scopes

```php
$g = Guard::fromRequest($request);   // or $auth->guard($request)
$g->check(); $g->guest(); $g->id(); $g->tenantId();
$g->via();          // 'jwt' | 'api_key' | 'session' | 'none'
$g->viaToken(); $g->viaSession();
$g->hasRole('admin'); $g->hasPermission('invoice:create');
$g->hasScope('reports:export');       // hierarchical
$t = Guard::actingAs('u1', ['reports'], roles: ['analyst']);   // test helper
```

Scopes/abilities are **colon-hierarchical** — a held scope satisfies every
descendant (`ScopeInheritance::satisfies`):

```php
ScopeInheritance::satisfies(['admin'],       'admin:users:write'); // true
ScopeInheritance::satisfies(['admin:users'], 'admin:posts');       // false
ScopeInheritance::satisfies(['adm'],         'admin');             // false (boundary)
// '*' grants all; bare (PAT) and 'scope:'-namespaced (OAuth2) both match.
```

## 5. AuthManager — named guards + providers

Config-driven (`config/auth.php`, read via `auth_config()`), no globals; guards
resolve an `AuthUserProxy` that **emits** an `Identity`.

```php
// config/auth.php
return [
    'defaults'  => ['guard' => 'web', 'provider' => 'users'],
    'guards'    => [
        'web'     => ['driver' => 'session', 'provider' => 'users'],
        'api'     => ['driver' => 'token',   'provider' => 'users'],
        'jwt'     => ['driver' => 'jwt',     'provider' => 'users'],
        'request' => ['driver' => 'request', 'provider' => 'users'],
    ],
    'providers' => ['users' => ['driver' => 'model']],
];
```

```php
// READ
$manager->guard('api')->user();      // ?Authenticatable (AuthUserProxy)
$manager->guard('jwt')->identity();  // kernel Identity
$manager->provider('users');
$manager->extend('sso', fn($req,$name,$cfg) => new GuardAccessor(...));
$manager->extendProvider('ldap', fn($name) => new LdapUserProvider(...));
$manager->forgetGuards();            // Swoole: clear per-request cache

// WRITE — the old front-door ergonomic (session guard):
$manager->guard('web')->attempt(['email' => …, 'password' => …], remember: true);
$manager->guard('web')->logout();
$manager->guard('web')->logoutOtherDevices($password);
// (a stateless guard throws on a write — attempt/logout need a session driver)

// ISSUE — stateless credentials, one call, no reaching into AuthService:
$manager->issueToken('u1', ['roles' => ['user']], 3600);        // access JWT
$manager->issueTokenPair('u1', device: $ua, ip: $ip);          // { accessToken, refreshToken, … }
```

**AuthManager is the single front door.** The Auth plugin's own controllers
route through it — `SessionAuthController` drives `$this->auth('web')->attempt()`
/ `->logout()` / `->logoutOtherDevices()`; `MobileAuthController` issues via
`$this->authManager()->issueTokenPair()` / `->issueToken()` (parity with the old
`AuthManager::issueToken('mobile', …)`). Controllers never touch
`AuthService`/`RefreshTokenService` directly. The one thing AuthManager does NOT
own is verifying an INCOMING token on a protected request — that runs in the
kernel SecurityGateway (`JwtAuthLayer`/`PersonalAccessTokenLayer`) *before* any
module loads, which is a GDA requirement, not a choice. Other PLUGINS still cross
the boundary through the published `AuthServiceContract` (AuthManager is
Auth-internal, deliberately not exposed).

- **`ModelUserProvider`** — resolves users from `UserServiceContract` (no ORM);
  `retrieveByCredentials` does the full timing-safe verify.
- **`AuthUserProxy`** — lightweight current user; `identity()` + HasApiTokens
  (`tokens()/token()/tokenCan()/createToken()`). Not the principal.
- **Drivers** (`Infrastructure/Auth/Drivers`) — `session`, `jwt`/`token` (rehydrate
  the gateway verdict), `request` (any). **Filesystem-scanned once per process**
  (a documented boot-time exception to the no-runtime-discovery rule).

**`StatefulSessionGuard`** (interactive login):

```php
$guard->attempt(['email' => $e, 'password' => $p], remember: true);
$guard->validate($creds); $guard->once($creds);
$guard->loginUsingId('u1', remember: true); $guard->login($user, remember: true);
$guard->logout(); $guard->logoutOtherDevices($password); $guard->viaRemember();
$guard->basic('email');   // HTTP Basic → null on success, 401 Response on fail
```

## 6. Session login + remember-me

```
POST /auth/login   { identifier, password, remember? }  → 200 {user} | 401
POST /auth/logout                                         → 204
GET  /auth/me                                             → identity | 401
```

`remember=true` issues an encrypted `remember_web` cookie holding a
`userId|token` **recaller** (`Recaller` — a flat pipe string, never unserialized).
With no live session, `SessionAuthStage` validates it by the token's SHA-256 hash
(`UserServiceContract::findByRememberToken`), re-opens the session, and **rotates**
the token + cookie (single-use window). Logout clears both.

**Post-login redirect.** The Session plugin's `StartSessionStage` records the
last eligible page view (GET + 2xx, HTML or Pageflow page object; auth/OAuth/
API/asset paths exempt — extend with `SESSION_PREVIOUS_EXEMPT`) under
`StartSessionStage::PREVIOUS_URL`. On successful login the redirect target is:
an explicit `redirectTo` on the request (query/body) → the recorded previous
page (pulled one-time) → `/`. Browser POSTs get a 302; AJAX callers get
`redirectTo` in the JSON payload. Every candidate passes an open-redirect
guard (relative `/…` paths only). SocialAuth's web callback honours the same
recorded page.

**Display identity.** `AuthService` fills `username`/`email` from the central
user store at issuance when the caller didn't supply them; they ride as OIDC
claims (`preferred_username`, `email`, `name`) on JWTs and as session keys, so
verification layers rebuild a full `Identity` without a DB read. The user-store
dependency is a lazy closure — never resolve `UserServiceContract` eagerly in
the AuthService factory (container cycle).

## 7. Personal access tokens (self-service)

First-party user API keys — **not** OAuth clients, **not** used by session login.

```
GET    /auth/tokens        → list mine (no secrets)
POST   /auth/tokens        { name, abilities[], ttl? } → 201 { id, token }  (once)
DELETE /auth/tokens/{id}   → 204 (mine only, else 404)
```

```php
$r = $auth->createPersonalAccessToken('u1', 'ci', ['deploy:run'], 86400);
$user = $manager->user();
$user->tokens(); $user->tokenCan('deploy:run'); $user->createToken('backup', ['storage:read']);
```

Only the SHA-256 is stored; prune expired with `auth:tokens:prune`.

## 8. Refresh tokens (revocable sessions)

`RefreshTokenServiceContract` — moved here from Tenancy (auth ≠ tenancy).

```php
$issued = $refresh->issue('u1', device: $ua, ip: $ip); // raw token shown ONCE
$rot    = $refresh->rotate($rawToken, $ip);            // → RefreshRotation
$refresh->revoke($rawToken); $refresh->revokeAllForUser('u1');
```

```
POST /auth/refresh          { token }  → new access JWT + rotated refresh | 401
POST /auth/refresh/logout   { token }  → 204
```

**One-time-use rotation with family reuse detection**: replaying a revoked token
(or losing a rotation race) burns the whole `family_id` and 401s. Only hashes are
stored. **Tenant-agnostic** — `tenantId` is a passthrough hint for the `tnt`
claim, never re-verified on refresh (the tenant-seat check is at tenant-select).

## 9. Transient token (first-party SPA)

`POST /auth/token/refresh` (auth-filtered) — a session-authenticated SPA mints a
short-lived (900s) JWT carrying the session identity's real permissions. A
Bearer/PAT caller is refused (session only).

## 10. Password reset

`PasswordBroker` (`PasswordResetBroker`) — CachePort-backed, enumeration-safe, no
token table:

```php
$res    = $broker->sendResetLink('alice@example.com');   // → token (email it) | INVALID_USER | THROTTLED
$status = $broker->reset('alice@example.com', $token, 'N3wPassw0rd!'); // PASSWORD_RESET
```

Sets the password via `UserServiceContract::resetPassword` (also clears remember
tokens) and burns the one-time hashed token.

## Exceptions

| Exception | HTTP | When |
|---|---|---|
| `AuthenticationException` | 401 | no/invalid credential (carries guards tried) |
| `AuthorizationException` | 403 | denied; `asNotFound()` masks as 404 |
| `MissingScopeException` | 403 | token lacks a scope (`scopes()`) |
| `InvalidAuthTokenException` | 401 | `::different()/expired()/revoked()` |
| `InvalidRefreshTokenException` | 401 | `::invalid()/reuseDetected()` |

Security layers never throw — these are for the service/controller layers.

## Controller ergonomics

- **`InteractsWithAuth`** — `$this->guard()`, `$this->identity()`, `$this->authId()`, `$this->tokenCan('write')`.
- **`InteractsWithAuthManager`** — `$this->auth('api')->user()`, `$this->authUser()` (route must `requires: ["auth.identity"]`).

```php
final class ReportController extends ApiController {
    use InteractsWithAuth;
    public function export(): Response {
        return $this->tokenCan('reports:export')   // hierarchical
            ? Response::json(['ok' => true]) : Response::forbidden();
    }
}
```

## Route reference

| Method | Path | Filters |
|---|---|---|
| POST | `/auth/login` | `throttle:10,1` |
| POST | `/auth/logout` | |
| GET  | `/auth/me` | |
| GET  | `/auth/tokens` | `auth` |
| POST | `/auth/tokens` | `auth`, `throttle:20,1` |
| DELETE | `/auth/tokens/{id}` | `auth` |
| POST | `/auth/token/refresh` | `auth` (transient SPA token) |
| POST | `/auth/refresh` | `throttle:30,1` (refresh rotation) |
| POST | `/auth/refresh/logout` | |

## Configuration (env)

`JWT_SECRET`, `JWT_ALGO` (HS256), `JWT_ISSUER`, `JWT_AUDIENCE`,
`JWT_PRIVATE_KEY`/`_FILE`, `JWT_KID`, `AUTH_PAT_TABLE`,
`AUTH_REFRESH_TTL` (30d), `AUTH_REFRESH_ACCESS_TTL` (900s),
`AUTH_GUARD`/`AUTH_PROVIDER`. Read via `env()`, never `getenv()`. Guard/provider
maps live in `config/auth.php` (project copy wins), read via `auth_config()`.

## Wiring checklist

1. `->withModules([... \Plugins\Auth\Provider::class])`.
2. Wire `JwtAuthLayer` + `PersonalAccessTokenLayer` in `->withSecurity([...])` (they need port instances).
3. Deps: `database.management`, `crypto.services` (HashingPort), `user.management`; sessions need essential Session + Cookie; `revokeJwt`/refresh-revocation need `CachePort`.
4. Run migrations: `personal_access_tokens`, `refresh_tokens` (central).
5. Routes using the AuthManager/PAT surface declare `"requires": ["auth.identity"]`; protect with the `auth` filter.

## Rules

**Do** — verify in SecurityLayers, issue in `AuthService` (never mix) · pin a single
JWT algo · PATs store only the hash, plaintext once · session login *after* verify,
rotate the id · remember-me/refresh: hash only, rotate on use, family reuse
detection · treat scopes hierarchically.

**Don't** — a SecurityLayer that throws · trust a `tnt` claim as authorization
(routing hint only) · re-check tenant seat on refresh (it's at tenant-select) ·
unserialize a recaller · confuse `personal_access_tokens` (user keys) with
`oauth_clients` (apps) · `getenv()` for a `JWT_*`/`AUTH_*` value.

---

*OAuth 2.1 / OIDC authorization-server flows live in the `Plugins\OAuth2` plugin.*

---

## Restored HKMCode flows (device sessions · mobile · OTP · social · RBAC)

The full old-framework auth flow is available. New pieces and how to use them:

### Web session security — fingerprint + device registry
Every stateful login is bound to a device **fingerprint** (`X-Client-Fingerprint`
header, else `sha256(ip|user-agent)`) and registered in the central
`auth_sessions` table. A request that can't reproduce the fingerprint, or whose
server-side row was revoked/expired, loses the session immediately — even if the
cookie is still live. Rolling refresh slides the expiry forward on activity.
`DeviceSessionService` orchestrates it; `config/auth.php` `session` block tunes
`ttl_days` / `refresh_days` / `client_fingerprint_header`.

- `GET  /auth/sessions` — list this user's active devices (current flagged).
- `DELETE /auth/sessions/{id}` — sign out one device.
- `POST /auth/logout-other-devices` `{ password }` — revoke every OTHER device
  (re-verifies the password first).

Run the `auth_sessions` migration (central).

### Mobile JWT flow (`/auth/mobile/*`)
- `POST /auth/mobile/login` `{ email|identifier, password }` → `{ user, tokens }`
  (access JWT + refresh). Add `client_id` + PKCE params (`redirect_uri`, `scope`,
  `state`, `code_challenge`, `code_challenge_method`) to switch to the **PKCE**
  shape → `{ code, state }`, exchanged at `POST /oauth/token` with the
  `code_verifier`. PKCE needs the route to also require `oauth.server`.
- `POST /auth/mobile/register` → same two shapes; auto-verifies the email
  (`AUTH_MOBILE_AUTOVERIFY=0` to disable).
- `POST /auth/mobile/logout` (Bearer) → blocklists the access token's `jti`.
- Refresh stays at `POST /auth/refresh` (DB-backed rotation + family reuse
  detection).

### OTP password reset (`/auth/password/*`)
`POST /auth/password/forgot` `{ email }` → always 200 (enumeration-safe), emails a
6-digit OTP via the OPTIONAL `MailPort` · `POST /auth/password/verify-otp`
`{ email, otp }` → `{ resetToken }` (single-use) · `POST /auth/password/reset`
`{ email, token, password }`. Needs `CachePort`.

### Social sign-in (`Plugins\SocialAuth`, solves `auth.social`)
- `GET /auth/social/{driver}` → provider redirect · `GET /auth/social/{driver}/callback`
  → session login + redirect (web), or `?mode=token` → `{ user, tokens }`.
- `POST /auth/social/{driver}/token` — native-SDK sign-in: verifies a Google
  `access_token`/`id_token` or an Apple `identity_token` (against Apple's JWKS)
  before find-or-create. Links live in central `social_identities`.

### RBAC via Casbin (`Plugins\Authorization`, solves `authorization.policy`)
When loaded, a user's roles + effective permissions are read from the policy
store and stamped into the session and JWT claims at login/issuance
(`RoleResolver`). Protect a route declaratively:

```jsonc
{ "method": "PUT", "path": "/api/users/{id}", "handler": "…",
  "filters": ["auth", "can:users,edit"], "requires": ["authorization.policy"] }
```

Seed the shipped role hierarchy (super/owner/admin/…): `hkm authz:seed`
(imports `plugins/Authorization/config/policy.seed.csv`; the wildcard model in
`rbac_model.conf` treats `*` object/action as full access). Policy rules are
control-plane → central connection.
