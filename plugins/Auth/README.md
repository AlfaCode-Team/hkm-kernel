# Auth — Authentication (`solves: auth.identity`)

The **single home for authentication** on the AlfacodeTeam PhpServicePlatform. It
decides *who* a caller is and gives you ergonomic ways to work with that identity.
It does **not** do authorization policy (that's your service layer, or
`Plugins\Authorization`) and it is **not** the multi-tenant control plane (that's
`Plugins\Tenancy`).

> 📄 A full typeset walkthrough ships alongside this file: [`AUTH_GUIDE.pdf`](AUTH_GUIDE.pdf).
> Architecture notes: `docs/ai-context/25_AUTH.md`.

---

## Part I — Requirements

### Module manifest

| Field | Value |
|---|---|
| `solves` | `auth.identity` |
| `requires` | `database.management`, `crypto.services`, `user.management`, `authorization.policy` |
| `exposes` | `AuthServiceContract`, `RefreshTokenServiceContract` |
| `views` | `resources/views` → namespace `auth`, `global: false` |
| Activation | **on-demand** (SecurityLayers are wired separately, in the bootstrap) |

Everything else in this plugin (`AuthManager`, `AuthService`, `DeviceSessionService`,
`RefreshTokenService`, repositories) is **internal**. Other plugins reach Auth only
through the two exposed contracts.

### Kernel ports it needs

| Port | Used for | Required? |
|---|---|---|
| `DatabasePort` | PAT / refresh-token / device-session tables | **yes** |
| `HashingPort` | password hashing + verification (`crypto.services`) | **yes** |
| `CachePort` | JWT `jti` deny-list, password-reset tokens + OTP | for revocation and the whole `/auth/password/*` flow |
| `SessionPort` | stateful web login (`Plugins\Session`, essential) | for session auth |
| `MailPort` | OTP email + password-changed notification | optional — flow degrades without it |

`PasswordBroker` is only bound **when a `CachePort` is available** — no cache, no
password-reset endpoints.

### Companion plugins

| Plugin | Why |
|---|---|
| `Plugins\User` (`user.management`) | the central identity store behind every provider |
| `Plugins\Session` + `Plugins\Cookie` | **essential** — required for session login and remember-me |
| `Plugins\Authorization` (`authorization.policy`) | roles/permissions stamped into sessions and JWT claims |
| `Plugins\Mail` + `Plugins\View` | rendering and sending the two auth emails |
| `Plugins\OAuth2` | OAuth 2.1 / OIDC **authorization server** — a different concern, see below |

### Database — every auth table is TENANT-scoped

**Central holds no sessions and no tokens.** All three tables are created by this
plugin's `database/tenant-template/` migrations and live in each **tenant**
database; the repositories resolve the per-request `DatabasePort` that
`TenantContextStage` rebinds.

| Table | Holds | Migration |
|---|---|---|
| `personal_access_tokens` | first-party user API keys (hash only) | `2026_06_05_000001_*`, `2026_06_27_000002_*` |
| `refresh_tokens` | revocable long-lived sessions, `family_id` lineage | `2026_07_04_000002_*` |
| `auth_sessions` | device-session registry + fingerprints | `2026_07_12_000001_*` |

```bash
hkm tenants:migrate            # applies tenant-template to every tenant DB
```

> Do **not** move these to the central connection. A repository pinned to
> `ConnectionManager->default()` will query a table central does not have.

### Configuration

#### Environment (all optional — declared in `module.json` `config[]`)

| Key | Default | Meaning |
|---|---|---|
| `JWT_SECRET` | — | HMAC signing secret (HS*) |
| `JWT_ALGO` | `HS256` | signing algorithm — pin ONE |
| `JWT_ISSUER` / `JWT_AUDIENCE` | — | `iss` / `aud`, verified on the way in |
| `JWT_PRIVATE_KEY` / `JWT_PRIVATE_KEY_FILE` | — | PEM for RS/ES/PS asymmetric signing |
| `JWT_KID` | — | key id in the JWT header (rotation) |
| `AUTH_PAT_TABLE` | `personal_access_tokens` | PAT table override |
| `AUTH_REFRESH_TTL` | `2592000` (30d) | refresh-token lifetime |
| `AUTH_REFRESH_ACCESS_TTL` | `900` | access JWT minted by a rotation |
| `AUTH_SESSION_TTL` | `30` (days) | absolute device-session lifetime |
| `AUTH_SESSION_REFRESH` | `7` (days) | rolling window — expiry slides forward on activity |
| `AUTH_FINGERPRINT_HEADER` | `X-Client-Fingerprint` | client-supplied fingerprint header |
| `AUTH_MOBILE_ACCESS_TTL` | — | mobile access-token lifetime |
| `AUTH_MOBILE_AUTOVERIFY` | on | auto-verify email on mobile register (`0` disables) |
| `AUTH_OTP_TTL` | `600` | password-reset OTP lifetime, seconds |
| `AUTH_GUARD` / `AUTH_PROVIDER` | `web` / `users` | defaults read by `config/auth.php` |

Read them with `env()` — **never `getenv()`** (`.env` values are injected into
`$_ENV`/`$_SERVER` only).

#### `config/auth.php`

Guard/provider maps. Resolution order: `projects/<name>/config/auth.php` (copy it
there to override) → `plugins/Auth/config/auth.php`. Read via `auth_config()`.

```php
return [
    'defaults'  => ['guard' => env('AUTH_GUARD', 'web'), 'provider' => env('AUTH_PROVIDER', 'users')],
    'guards'    => [
        'web'     => ['driver' => 'session', 'provider' => 'users'],
        'api'     => ['driver' => 'token',   'provider' => 'users'],
        'jwt'     => ['driver' => 'jwt',     'provider' => 'users'],
        'request' => ['driver' => 'request', 'provider' => 'users'],
    ],
    'providers' => ['users' => ['driver' => 'model']],
    'session'   => [
        'ttl_days'                  => (int) (env('AUTH_SESSION_TTL') ?: 30),
        'refresh_days'              => (int) (env('AUTH_SESSION_REFRESH') ?: 7),
        'client_fingerprint_header' => env('AUTH_FINGERPRINT_HEADER') ?: 'X-Client-Fingerprint',
    ],
];
```

### Wiring checklist

1. Register the provider: `->withModules([..., \Plugins\Auth\Provider::class])`.
2. Wire the verification layers in `->withSecurity([...])` — they take **port
   instances**, so they cannot self-register (step 3 below).
3. Make `Plugins\Session` + `Plugins\Cookie` essential (session login), e.g. via
   `proj.json` `"essentials"`.
4. Run the tenant migrations: `hkm tenants:migrate`.
5. Any route using the AuthManager/PAT surface declares `"requires":
   ["auth.identity"]`; protect it with the `auth` route filter.
6. Routes that **send mail** (`/auth/password/forgot`, `/auth/password/reset`)
   declare `"requires": ["mail.delivery", "view.rendering"]` — do not rely on
   another module pulling them in transitively.

---

## Part II — Concepts

### The one split to remember

| | Where it lives | Classes |
|---|---|---|
| **Issuance** — mint credentials | service layer | `AuthServiceContract`, `RefreshTokenServiceContract` |
| **Verification** — check a credential | SecurityGateway (before any module loads) | `JwtAuthLayer`, `PersonalAccessTokenLayer`, `SessionAuthStage` |

The principal produced by verification is the kernel's immutable `Identity`,
carried on the request. Everything else here (Guard, AuthManager, AuthUserProxy)
is a **projection** over that `Identity` — none of them replace it.

### The five authentication methods

| Method | Credential | Verified / issued by |
|---|---|---|
| JWT (Bearer) | `Authorization: Bearer <jwt>` | `JwtAuthLayer` / `AuthService::issueJwt` |
| Personal access token | `Authorization: Bearer <id>.<secret>` | `PersonalAccessTokenLayer` / `AuthService::createPersonalAccessToken` |
| Session (web/AJAX) | session cookie + `remember_web` cookie | `SessionAuthStage` / `AuthService::startSession` |
| Refresh token | opaque token in POST body | `RefreshTokenService::rotate` / `::issue` |
| Transient token | session → short JWT | `TransientTokenController` |

### 1. The principal: `Identity`

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

### 2. Verification — SecurityGateway layers

Wire the layers in the kernel builder; they run before any module loads and
**never throw** (they return a `SecurityVerdict`).

```php
->withSecurity([
    new FirewallLayer(...), new RateLimiterLayer(...), new CsrfTokenLayer(...),
    new JwtAuthLayer(
        secret:      env('JWT_SECRET'),
        algo:        env('JWT_ALGO', 'HS256'),
        issuer:      env('JWT_ISSUER'),
        audience:    env('JWT_AUDIENCE'),
        leeway:      0,
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
`SessionAuthStage` runs at `after.load` **priority 22** and attaches a
`tokenType: 'session'` Identity — the same `auth` route filter then covers token
*and* session callers. A token Identity already present is left untouched.

### 3. Issuance — `AuthServiceContract`

| Method | Notes |
|---|---|
| `issueJwt(userId, claims, ttl): string` | adds `iat/nbf/exp/jti` + `iss/aud`; asymmetric signs with the private key |
| `revokeJwt(jti, ttl)` | deny-lists a `jti` (key `auth:jwt:revoked:<jti>`) |
| `createPersonalAccessToken(userId, name, abilities, ttl)` | `{id, token}`; plaintext once, hash stored |
| `revokePersonalAccessToken(id)` | |
| `tokensFor(userId): list<TokenDTO>` | a user's PATs, no secrets |
| `guard(Request): Guard` | read-only projection |
| `startSession(session, userId, roles, perms, tenantId, username, email, fullName, avatarUrl)` | rotates the session id, stores identity |
| `endSession(session)` | invalidate + rotate |
| `hashPassword` / `verifyPassword` | bcrypt/argon2, timing-safe |

```php
$jwt = $auth->issueJwt('user-123', [
    'roles' => ['admin'], 'permissions' => ['invoice:create'], 'tnt' => 'tenant-9',
], ttlSeconds: 3600);
$auth->revokeJwt($jti, 3600);   // kill it before exp
```

### 4. Guard + hierarchical scopes

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

### 5. AuthManager — named guards + providers

Config-driven (`config/auth.php`, read via `auth_config()`), no globals; guards
resolve an `AuthUserProxy` that **emits** an `Identity`.

```php
// READ
$manager->guard('api')->user();      // ?Authenticatable (AuthUserProxy)
$manager->guard('jwt')->identity();  // kernel Identity
$manager->user('api'); $manager->check(); $manager->id();
$manager->provider('users');
$manager->extend('sso', fn($req,$name,$cfg) => new GuardAccessor(...));
$manager->extendProvider('ldap', fn($name) => new LdapUserProvider(...));
$manager->resolveUsersUsing($closure);
$manager->forgetGuards();            // Swoole: clear per-request cache
$manager->setRequest($request);      // done for you by the controller concern

// WRITE — the front-door ergonomic (session guard):
$manager->guard('web')->attempt(['email' => …, 'password' => …], remember: true);
$manager->guard('web')->logout();
$manager->guard('web')->logoutOtherDevices($password);
// (a stateless guard throws on a write — attempt/logout need a session driver)

// ISSUE — stateless credentials, one call, no reaching into AuthService:
$manager->issueToken('u1', ['roles' => ['user']], 3600);       // access JWT
$manager->issueTokenPair('u1', device: $ua, ip: $ip);          // { accessToken, refreshToken, … }
```

**AuthManager is the single front door.** The Auth plugin's own controllers route
through it — `SessionAuthController` drives `$this->auth('web')->attempt()` /
`->logout()` / `->logoutOtherDevices()`; `MobileAuthController` issues via
`$this->authManager()->issueTokenPair()` / `->issueToken()`. Controllers never
touch `AuthService`/`RefreshTokenService` directly. The one thing AuthManager does
NOT own is verifying an INCOMING token on a protected request — that runs in the
kernel SecurityGateway *before* any module loads, which is a GDA requirement, not
a choice. Other PLUGINS cross the boundary through the published
`AuthServiceContract` (AuthManager is Auth-internal, deliberately not exposed).

- **`ModelUserProvider`** — resolves users from `UserServiceContract` (no ORM);
  `retrieveByCredentials` does the full timing-safe verify.
- **`AuthUserProxy`** — lightweight current user; `identity()` + HasApiTokens
  (`tokens()/token()/tokenCan()/createToken()`). Not the principal.
- **Drivers** (`Infrastructure/Auth/Drivers`) — `session`, `jwt`/`token`
  (rehydrate the gateway verdict), `request` (any). **Filesystem-scanned once per
  process** (a documented boot-time exception to the no-runtime-discovery rule).

**`StatefulSessionGuard`** (interactive login):

```php
$guard->attempt(['email' => $e, 'password' => $p], remember: true);
$guard->validate($creds); $guard->once($creds);
$guard->loginUsingId('u1', remember: true); $guard->login($user, remember: true);
$guard->logout(); $guard->logoutOtherDevices($password); $guard->viaRemember();
$guard->basic('email');   // HTTP Basic → null on success, 401 Response on fail
```

---

## Part III — The flows

### 6. Session login + remember-me

```
POST /auth/login   { identifier|email, password, remember?, redirectTo? }  → 200 {user, redirectTo} | 401
POST /auth/logout                                                          → 204
GET  /auth/me                                                              → identity | 401
```

`remember=true` issues an encrypted `remember_web` cookie holding a
`userId|token` **recaller** (`Recaller` — a flat pipe string, never unserialized).
With no live session, `SessionAuthStage` validates it by the token's SHA-256 hash
(`UserServiceContract::findByRememberToken`), re-opens the session, and **rotates**
the token + cookie (single-use window). Logout clears both.

**Post-login redirect.** The Session plugin's `StartSessionStage` records the last
eligible page view (GET + 2xx, HTML or Pageflow page object; auth/OAuth/API/asset
paths exempt — extend with `SESSION_PREVIOUS_EXEMPT`) under
`StartSessionStage::PREVIOUS_URL`. On successful login the target is: explicit
`redirectTo` on the request (query/body) → the recorded previous page (pulled
one-time) → `/`. Browser POSTs get a 302; AJAX callers get `redirectTo` in the
JSON payload. Every candidate passes an open-redirect guard (relative `/…` paths
only). SocialAuth's web callback honours the same recorded page.

**Display identity.** `AuthService` fills `username`/`email` from the central user
store at issuance when the caller didn't supply them; they ride as OIDC claims
(`preferred_username`, `email`, `name`) on JWTs and as session keys, so
verification layers rebuild a full `Identity` without a DB read. The user-store
dependency is a lazy closure — never resolve `UserServiceContract` eagerly in the
AuthService factory (container cycle).

### 7. Device sessions — fingerprint + registry

Every stateful login is bound to a device **fingerprint**
(`X-Client-Fingerprint` header, else `sha256(ip|user-agent)`) and registered in
the tenant `auth_sessions` table. A request that can't reproduce the fingerprint,
or whose server-side row was revoked/expired, loses the session immediately — even
if the cookie is still live. Rolling refresh slides the expiry forward on
activity. `DeviceSessionService` orchestrates it; the `config/auth.php` `session`
block tunes it.

```
GET    /auth/sessions              → list this user's devices (current flagged)
DELETE /auth/sessions/{id}         → sign out one device
POST   /auth/logout-other-devices  { password } → revoke every OTHER device
```

```php
$devices->establish($session, $request, $userId);   // at login
$devices->verify($session, $request);               // per request (SessionAuthStage)
$devices->revokeOthers($session, $request, $userId);// keep this one
$devices->revokeById($userId, $sessionId);
$devices->revokeAll($userId);                       // keep NONE — used by password reset
$devices->listDevices($userId, $session);
$devices->teardown($session);                       // at logout
```

### 8. Personal access tokens (self-service)

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

Only the SHA-256 is stored.

### 9. Refresh tokens (revocable sessions)

`RefreshTokenServiceContract` — lives here, not in Tenancy (auth ≠ tenancy).

```php
$issued = $refresh->issue('u1', tenantId: null, device: $ua, ip: $ip); // raw token shown ONCE
$rot    = $refresh->rotate($rawToken, $ip);            // → RefreshRotation
$refresh->revoke($rawToken);
$refresh->revokeAllForUser('u1');                      // logout everywhere
```

```
POST /auth/refresh          { token }  → new access JWT + rotated refresh | 401
POST /auth/refresh/logout   { token }  → 204
```

**One-time-use rotation with family reuse detection**: replaying a revoked token
(or losing a rotation race) burns the whole `family_id` and 401s. Only hashes are
stored, in the **tenant** `refresh_tokens` table. The `tenantId` argument is a
passthrough hint for the `tnt` claim, never re-verified on refresh (the
tenant-seat check happens at tenant-select).

### 10. Transient token (first-party SPA)

`POST /auth/token/refresh` (auth-filtered) — a session-authenticated SPA mints a
short-lived (900s) JWT carrying the session identity's real permissions. A
Bearer/PAT caller is refused (session only).

### 11. Mobile JWT flow (`/auth/mobile/*`)

- `POST /auth/mobile/login` `{ email|identifier, password }` → `{ user, tokens }`
  (access JWT + refresh). Add `client_id` + PKCE params (`redirect_uri`, `scope`,
  `state`, `code_challenge`, `code_challenge_method`) to switch to the **PKCE**
  shape → `{ code, state }`, exchanged at `POST /oauth/token` with the
  `code_verifier`. PKCE needs the route to also require `oauth.server`.
- `POST /auth/mobile/register` → same two shapes; auto-verifies the email
  (`AUTH_MOBILE_AUTOVERIFY=0` to disable).
- `POST /auth/mobile/logout` (Bearer) → blocklists the access token's `jti`.
- Refresh stays at `POST /auth/refresh`.

### 12. Password reset (OTP)

Three steps, `CachePort`-backed — **no token table**. The cache must be
**cross-process** (Redis, or a file-backed adapter); a per-request in-memory cache
loses the OTP between the two requests and every code looks instantly expired.

```
POST /auth/password/forgot     { email }                       → 200 ALWAYS (enumeration-safe)
POST /auth/password/verify-otp { email, otp }                  → { resetToken } | 400
POST /auth/password/reset      { email, token, password,
                                 password_confirmation? }      → 200 | 400 | 422
```

```php
// Or drive it directly through the port:
$res    = $broker->sendResetLink('alice@example.com');   // → token | INVALID_USER | THROTTLED
$sent   = $broker->sendOtp('alice@example.com');         // → ['otp' =>…, 'email' =>…] | null
$token  = $broker->verifyOtp('alice@example.com', '123456');   // → reset token | null
$status = $broker->reset('alice@example.com', $token, 'N3wPassw0rd!'); // PASSWORD_RESET
$ok     = $broker->validateToken('alice@example.com', $token);
```

Statuses: `PASSWORD_RESET`, `RESET_LINK_SENT`, `INVALID_USER`, `INVALID_TOKEN`,
`THROTTLED`.

**Lifetimes & limits**

| Thing | Value | Key |
|---|---|---|
| Reset token | 3600s, one-time | `auth:pwreset:tok:sha256(email)` |
| OTP | `AUTH_OTP_TTL` (600s), one-time, 6 digits | `auth:pwreset:otp:…` |
| Re-issue throttle | 60s per account | `auth:pwreset:thr:…` |
| Wrong-OTP budget | **5 per account**, then the code is burned | `auth:pwreset:try:…` |

The account is addressed by the cache key `sha256(email)`, so a token minted for
one address can never reset another — swapping the email swaps the slot it is
compared against, and `hash_equals` fails.

**What a completed reset does**

1. Sets the password (`UserServiceContract::resetPassword` — transactional,
   audited, optional HIBP breach screening via `USER_BREACH_CHECK`).
2. Clears the remember-me token.
3. **Revokes every refresh token** for the user.
4. **Revokes every device session** for the user.
5. Burns the reset token, the throttle, and any outstanding OTP.
6. Emails a **password-changed notification** (best-effort).

Revocation happens *before* the token burn, deliberately: if a sweep fails the
reset is not reported as successful and the token stays valid for a retry (the
password write is idempotent).

**Server-side validation on `reset`** — min 8 bytes, max 4096 (hashing-DoS
guard), no NUL byte, password ≠ email, and `password_confirmation` enforced
whenever the client sends it.

### 13. Emails

Two templates in `resources/views/`, namespace `auth`, `global: false`:

| View | Sent when |
|---|---|
| `auth::password-otp` | `/auth/password/forgot` — the 6-digit code |
| `auth::password-changed` | after a successful reset — the takeover tripwire |

Neither contains a login link or a token beyond the OTP itself. Both are
brand-neutral inline-CSS HTML. **Override** either from a project by placing your
own `resources/views/auth/password-otp.php` in the project view path — the
project-first cascade wins (see RESOURCE RESOLUTION in `CLAUDE.md`).

Both routes must declare `"requires": ["mail.delivery", "view.rendering"]`.
Without a bound `MailPort` the flow still works — it just sends nothing.

### 14. Social sign-in (`Plugins\SocialAuth`, solves `auth.social`)

- `GET /auth/social/{driver}` → provider redirect · `GET /auth/social/{driver}/callback`
  → session login + redirect (web), or `?mode=token` → `{ user, tokens }`.
- `POST /auth/social/{driver}/token` — native-SDK sign-in: verifies a Google
  `access_token`/`id_token` or an Apple `identity_token` (against Apple's JWKS)
  before find-or-create. Links live in `social_identities`.

### 15. RBAC via Casbin (`Plugins\Authorization`, solves `authorization.policy`)

When loaded, a user's roles + effective permissions are read from the policy store
and stamped into the session and JWT claims at login/issuance (`RoleResolver`).
Protect a route declaratively:

```jsonc
{ "method": "PUT", "path": "/api/users/{id}", "handler": "…",
  "filters": ["auth", "can:users,edit"], "requires": ["authorization.policy"] }
```

Seed the shipped role hierarchy: `hkm authz:seed`.

---

## Part IV — Reference

### Route reference

| Method | Path | Filters | Extra `requires` |
|---|---|---|---|
| POST | `/auth/login` | `throttle:10,1` | |
| POST | `/auth/logout` | | |
| GET | `/auth/me` | | |
| GET | `/auth/sessions` | `auth` | |
| DELETE | `/auth/sessions/{id}` | `auth` | |
| POST | `/auth/logout-other-devices` | `auth`, `throttle:5,1` | |
| GET | `/auth/tokens` | `auth` | |
| POST | `/auth/tokens` | `auth`, `throttle:20,1` | |
| DELETE | `/auth/tokens/{id}` | `auth` | |
| POST | `/auth/token/refresh` | `auth` | |
| POST | `/auth/refresh` | `throttle:30,1` | |
| POST | `/auth/refresh/logout` | | |
| POST | `/auth/mobile/login` | `throttle:10,1` | |
| POST | `/auth/mobile/register` | `throttle:6,1` | |
| POST | `/auth/mobile/logout` | `auth` | |
| POST | `/auth/password/forgot` | `throttle:5,1` | `mail.delivery`, `view.rendering` |
| POST | `/auth/password/verify-otp` | `throttle:10,1` | |
| POST | `/auth/password/reset` | `throttle:10,1` | `mail.delivery`, `view.rendering` |

A project may veto any of these without forking the plugin, via `proj.json`:

```jsonc
{ "routePolicy": { "disable": ["POST /auth/mobile/register", "auth.identity"] } }
```

### Controller ergonomics

- **`InteractsWithAuth`** — `$this->guard()`, `$this->identity()`, `$this->authId()`, `$this->tokenCan('write')`.
- **`InteractsWithAuthManager`** — `$this->auth('api')->user()`, `$this->authUser()`, `$this->authManager()` (route must `requires: ["auth.identity"]`).

```php
final class ReportController extends ApiController {
    use InteractsWithAuth;
    public function export(): Response {
        return $this->tokenCan('reports:export')   // hierarchical
            ? Response::json(['ok' => true]) : Response::forbidden();
    }
}
```

### Exceptions

| Exception | HTTP | When |
|---|---|---|
| `AuthenticationException` | 401 | no/invalid credential (carries guards tried) |
| `AuthorizationException` | 403 | denied; `asNotFound()` masks as 404 |
| `MissingScopeException` | 403 | token lacks a scope (`scopes()`) |
| `InvalidAuthTokenException` | 401 | `::different()/expired()/revoked()` |
| `InvalidRefreshTokenException` | 401 | `::invalid()/reuseDetected()` |

Security layers never throw — these are for the service/controller layers.

### CLI

| Command | Does |
|---|---|
| `auth:tokens:prune` | delete expired personal access tokens |

> **Known limitation:** `auth:tokens:prune` still resolves the ConnectionManager
> default (central), but `personal_access_tokens` is tenant-scoped — so it targets
> a table central does not have. It needs to iterate the tenant registry and run
> once per tenant connection. Treat it as inoperative until then.

### Rules

**Do** — verify in SecurityLayers, issue in `AuthService` (never mix) · pin a
single JWT algo · PATs store only the hash, plaintext once · session login *after*
verify, rotate the id · remember-me/refresh: hash only, rotate on use, family
reuse detection · treat scopes hierarchically · revoke every credential on a
password reset.

**Don't** — a SecurityLayer that throws · trust a `tnt` claim as authorization
(routing hint only) · re-check tenant seat on refresh (it's at tenant-select) ·
unserialize a recaller · confuse `personal_access_tokens` (user keys) with
`oauth_clients` (apps) · `getenv()` for a `JWT_*`/`AUTH_*` value · pin an auth
repository to the central connection · back the password-reset flow with a
per-process in-memory `CachePort`.

---

*OAuth 2.1 / OIDC authorization-server flows live in the `Plugins\OAuth2` plugin.*
