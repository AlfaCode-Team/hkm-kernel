# User Plugin

> Solves: **`user.management`** · Namespace: **`Plugins\User\`** · Type: on-demand GDA module

The User plugin owns the **user.management** business domain on the AlfacodeTeam
PhpServicePlatform (GDA) framework. It provides enterprise-grade user
registration, lookup, partial update, email verification, soft-deletion, and
**timing-safe, rate-limited credential verification** — all over the GLOBAL
central `users` identity table.

It is the canonical reference for how a first-party plugin is structured: pure
Domain, an Application service that owns the transaction + events, Infrastructure
adapters behind ports, and a published API contract that other modules consume.

---

## Table of contents

1. [What it does](#what-it-does)
2. [Architecture at a glance](#architecture-at-a-glance)
3. [Directory layout](#directory-layout)
4. [Data model](#data-model)
5. [HTTP API](#http-api)
6. [Web UI (AJAX + CSRF)](#web-ui-ajax--csrf)
7. [Tenant-scoped sub-resources (feedback & settings)](#tenant-scoped-sub-resources-feedback--settings)
8. [Security model](#security-model)
9. [Reliability: the transactional outbox](#reliability-the-transactional-outbox)
10. [Installation & wiring](#installation--wiring)
11. [Configuration](#configuration)
12. [Using it from another plugin](#using-it-from-another-plugin)
13. [Using it from a project](#using-it-from-a-project)
14. [CLI](#cli)
15. [Testing](#testing)
16. [Extending the pattern](#extending-the-pattern)

---

## What it does

| Capability | Entry point | Notes |
|---|---|---|
| Register (public) | `POST /ajx/users` | Public self-signup, rate-limited. Returns `202 {status:"pending_verification"}` — **no identity data**. Queues a verification email (optional `MailPort`). May submit a profile block → tenant `user_profiles`. Emits `user.registered` |
| Register (admin) | `POST /ajx/admin/users` | `auth` + `user:create`. Returns the FULL created record for the admin table |
| Verify email (public) | `POST /ajx/users/verify` | **Unauthenticated**, token-based: SHA-256-stored, one-time, 24h expiry. Sets `email_verified_at` |
| Verify email (self/admin) | `POST /ajx/users/{id}/verify-email` | Authenticated variant (self or `user:update-any`) |
| List users | `GET /ajx/users` | Admin-only; keyset paginated |
| Show a user | `GET /ajx/users/{id}` | Self or `user:read-any` |
| Update (partial) | `PUT/PATCH /ajx/users/{id}` | Self or `user:update-any`; optimistic-locked; emits `user.updated` |
| Soft-delete | `DELETE /ajx/users/{id}` | Self or `user:delete-any`; emits `user.deleted` |
| Verify credentials | `UserServiceContract::verifyCredentials()` | Timing-safe, lockout, rehash-on-login; requires a verified email |
| **Settings** | `GET/PUT /ajx/{profile,preferences,privacy,notification-preferences}` | TENANT-scoped, self-only; one consolidated service |
| HTML UI | `GET /users[...]`, `/account/settings` | AJAX-driven, cookie auth, CSRF on every form |

> **Recent changes**
> - **Feedback moved out** into its own [`Plugins\Feedback`](../Feedback/README.md) plugin (one plugin, one domain). The `/ajx/feedback` routes + `user_feedback` table now live there.
> - **Registration split** into public (`registerPublic` → token only) vs admin (`register` → full record); public **email verification is token-based** (hashed, one-time, 24h) via `verifyEmailByToken`.
> - **Input DTOs** now extend `Plugins\Validation\AbstractDto` and declare `rules()` instead of hand-rolled validation.

---

## Architecture at a glance

```
HTTP ─▶ UserController (thin)              CLI ─▶ user:outbox:relay
          │  DTO → service → Response               │
          ▼                                         ▼
   UserServiceContract  ◀── published to other modules
          │
   UserService (Application)
   ├─ authorization (Identity)            ── self-or-permission
   ├─ TransactionManager  begin/commit/rollback
   ├─ DomainEventCollector (in-tx buffer)
   ├─ OutboxPort  ─▶ user_outbox (same tx)  ── at-least-once events
   ├─ HashingPort (crypto.services)         ── bcrypt, rehash-on-login
   ├─ CachePort  ─▶ login lockout
   └─ AuditLogger
          │
   UserStore (port)  ◀── UserRepository (central DatabasePort, global, version-locked)
          │
   User aggregate (Domain — zero external imports)
   ├─ UserId (monotonic ULID), Username, Email, PasswordPolicy
   └─ records UserRegistered/Updated/Deleted domain events
                (login gate = email_verified_at; no status column)
```

**The five GDA access rules hold:** Controller → Service (contract only),
Service → Repository + Gateway, Repository → DatabasePort only, Domain imports
nothing external.

---

## Directory layout

```
plugins/User/
├── module.json                       single source of truth (routes, requires, config)
├── Provider.php                      DI wiring + CLI registration
├── API/
│   ├── Contracts/UserServiceContract.php       the ONLY published interface
│   ├── DTOs/                                    Register/Update/VerifyEmail/User/ListUsersQuery/UserPage
│   │                                            + Submit/ListFeedbackQuery/FeedbackPage
│   │                                            + Update{Profile,Preferences,Privacy,NotificationPreferences}
│   └── IntegrationEvents/                       UserRegistered/Updated/Deleted, FeedbackSubmitted, Generic
├── Application/
│   ├── Ports/ UserStore, OutboxPort, FeedbackStore   internal DIP seams (testability)
│   └── Services/  UserService, FeedbackService, UserSettingsService
├── Domain/
│   ├── Entities/  User, FeedbackEntry, UserProfile, UserPreferences,
│   │              UserPrivacySettings, UserNotificationPreferences
│   ├── Events/                                   UserRegistered/Updated/Deleted domain events
│   ├── Exceptions/DuplicateUserException.php
│   └── ValueObjects/  UserId, Ulid, Username, Email, PasswordPolicy,
│                      Feedback{Id,Category,Rating,Status,Message}, Theme, ProfileVisibility
├── Infrastructure/
│   ├── Audit/AuditLogger.php
│   ├── Cli/RelayUserOutboxCommand.php           user:outbox:relay
│   ├── Http/Controllers/  UserController, UserPageController,
│   │                      FeedbackController, UserSettingsController
│   ├── Outbox/  OutboxWriter, OutboxRelay
│   └── Persistence/  UserRepository (central), FeedbackRepository + UserSettingsRepository (tenant)
├── config/user.php
├── database/
│   ├── migrations/        create_user_table, create_user_outbox_table   (CENTRAL)
│   ├── tenant-template/   user_profiles, user_privacy_settings, user_preferences,
│   │                      user_notification_preferences, user_feedback   (per-TENANT)
│   ├── seeders/UserSeeder.php
│   └── factories/UserFactory.php
└── resources/views/  layouts/app.php, users/{index,create,edit,show}.php,
                      account/{settings,feedback}.php
```

---

## Data model

`users` is the **GLOBAL central identity table** (authentication is centralized,
username/email globally unique). Owned by the migration; the repository never
alters schema.

| Column | Type | Purpose |
|---|---|---|
| `id` | bigint PK | internal surrogate (never leaves persistence) |
| `user_id` | char(31) | **public** ULID identifier |
| `username` | varchar(50) | **globally** unique |
| `email` | varchar(150) | **globally** unique, lowercased |
| `password_hash` | char(60) | bcrypt (exactly 60 chars) |
| `remember_token` | char(64) null | SHA-256 of the remember-me token |
| `version` | int unsigned | optimistic-lock version |
| `email_verified_at` | timestamp null | set on confirmation — **this is the login gate** |
| `created_at`/`updated_at`/`deleted_at` | timestamps | soft-delete aware |

Uniqueness is **global** (`uniq_username`, `uniq_email`).

> **Login gate = a verified email.** There is no `status` column. A user can
> authenticate only once `email_verified_at` is set (`UserService::verifyCredentials`
> checks `User::canLogin()`); "disable an account" is done via soft delete. The
> earlier `status` / `auth_provider` / `provider_subject` / `is_platform_admin` /
> `last_login_at` columns were removed to keep the table lean — federation and
> platform-admin, if needed, belong in their own tables/claims.

The repository and the `user_outbox` writer are **pinned to the central
connection** (the `ConnectionManager` default) so identity I/O always targets the
central database regardless of any per-request `DatabasePort` rebinding. A second
table, `user_outbox`, stores integration events for reliable delivery.

---

## HTTP API

All API responses use the framework envelope:

```jsonc
// success
{ "data": { "id": "01J…", "username": "jane", "email": "jane@example.com",
            "emailVerified": true, "createdAt": "2026-…" } }

// list (keyset paginated)
{ "data": [ … ], "meta": { "count": 25, "limit": 25, "has_more": true,
                           "next_cursor": "01J…" } }

// error
{ "error": { "code": "…", "message": "…", "fields": { "email": "…" } } }
```

### Register

```bash
curl -X POST https://app.example.com/ajx/users \
  -H 'Content-Type: application/json' \
  -d '{"username":"jane","email":"jane@example.com","password":"C0rrectHorse!"}'
# 201 → { "data": { … } }   emits user.registered
```

### List (paginate)

```bash
curl 'https://app.example.com/ajx/users?limit=50&after=01J…' \
  -H 'Authorization: Bearer <token>'    # or same-site session cookie
```

### Update (partial / PATCH semantics)

```bash
curl -X PUT https://app.example.com/ajx/users/01J… \
  -H 'Content-Type: application/json' -H 'X-CSRF-Token: …' \
  -d '{"email":"new@example.com"}'      # only changed fields; bumps version
```

A concurrent edit that loses the version race → **HTTP 409** (OptimisticLock).
A duplicate username/email → **HTTP 409/422** (DuplicateUserException).

---

## Web UI (AJAX + CSRF)

`UserPageController` renders four pages (`/users`, `/users/create`,
`/users/{id}`, `/users/{id}/edit`). Each is a thin HTML shell that hydrates over
AJAX against `/ajx/users`. Authentication is **same-site cookie** (no bearer
token in the browser).

**CSRF on every form:** the page controller (via `ViewController` →
`InteractsWithCsrf`) mints an HMAC token bound to a dedicated `csrf_bind`
cookie. Each page exposes it as `<meta name="csrf-token">` and a hidden
`_csrf_token` field; the shared `window.UserApp` client sends it as the
`X-CSRF-Token` header on every unsafe (POST/PUT/PATCH/DELETE) request.

> **Project requirement:** wire a `CsrfTokenLayer` in `withSecurity()` with
> `bindCookie: 'csrf_bind'`, the same `lifetime` as `CSRF_LIFETIME`, and **do not
> exempt `/api`** (the UI authenticates by cookie, so the write endpoints must be
> CSRF-checked). Otherwise tokens are sent but never validated.

---

## Tenant-scoped sub-resources (feedback & settings)

Beyond central identity, the plugin owns per-user data that lives in the
**tenant** database (not central): **feedback** and the four **settings**
singletons (profile, preferences, privacy, notification preferences).

Key differences from the identity tables:

- **Tenant-routed, not central.** Their repositories take the request's
  `DatabasePort` **after** `TenantContextStage` rebinds it — so rows land in the
  caller's tenant DB. Schema ships in `database/tenant-template/` and is applied
  per-tenant by the Tenancy tooling, **not** `migrate:run`.
- **`user_id` is the ULID** (`char(31)`, the central `users.user_id`) — a soft
  reference, no cross-DB foreign key.
- **Guarded by `auth` + `tenant` filters.** Every route declares
  `"filters": ["auth", "tenant"]`; the `tenant` filter (from the Tenancy plugin)
  returns **409** when no tenant is active, so these never hit central by mistake.
- **Self-scoped.** The user id always comes from `Identity`, never the body.

### Feedback — full CRUD

| Verb | Path | Who | Service |
| --- | --- | --- | --- |
| Create | `POST /ajx/feedback` | any authenticated user (`throttle:5,1`) | `FeedbackService::submit` |
| List | `GET /ajx/feedback` | `feedback:manage` (admin triage) | `list` |
| Read one | `GET /ajx/feedback/{id}` | self or `feedback:manage` | `find` |
| Update status | `PATCH /ajx/feedback/{id}` | `feedback:manage` (forward-only) | `updateStatus` |

Emits `feedback.submitted` (dispatched **directly** after the write — not via the
outbox, since the write is a single tenant-scoped insert).

### Settings — read/update, one service

`UserSettingsService` + `UserSettingsRepository` back all four resources
(`getX`/`updateX`); each is `GET/PUT /ajx/{profile,preferences,privacy,notification-preferences}`,
self-scoped, idempotent `PUT` via the portable `upsert`, audited on write. Demo
UI at `/account/settings` and `/account/feedback`.

> **Internal, not published.** Feedback + settings are consumed only by this
> plugin's own controllers, so their services are bound `bindInternal` and are
> **not** in `exposes()` — only `UserServiceContract` is cross-module. Services
> return the domain **entity**; the controller serialises via `entity->toArray()`
> (no separate output DTO).

---

## Security model

| Concern | Mechanism |
|---|---|
| Authorization | In the **service**: admins act on anyone (`user:list`, `user:*-any`); a non-admin only on their own record (`hash_equals` self-check) |
| Password storage | `HashingPort` (bcrypt); never `password_hash()` directly; plaintext never persisted/logged/returned |
| Password strength | `PasswordPolicy` VO — length 12–72, ≥3 char classes, deny-list |
| Login timing | Constant-time decoy hash for unknown users |
| Brute force | Per-identifier lockout via `CachePort` (5 failures / 15 min) |
| Hash upgrades | `needsRehash()` → transparent rehash on successful login |
| Central identity | Identity is GLOBAL (central `users`); repository pinned to the central connection |
| PII in logs | Exception context carries IDs only; audit pseudonymises identifiers |
| Credentials in transit | `password_hash` / `remember_token` never appear in any DTO/JSON |
| Audit | `AuditLogger` writes structured JSON for register/update/delete/login/lockout/rehash |

---

## Reliability: the transactional outbox

Integration events are **not** dispatched directly after commit (which can be
lost on a crash). Instead:

1. The service writes the event into `user_outbox` **inside the same
   transaction** as the state change (atomic).
2. `user:outbox:relay` (cron/supervised) reads pending rows and dispatches them
   to the `EventBus` — **at-least-once**, idempotency keyed by the event UUID.

```
register/update/delete ──tx──▶ [users row + user_outbox row]  COMMIT
                                            │
        cron: php cli user:outbox:relay ────▶ EventBus ──▶ subscribers
```

Consumers must dedupe on the event id (it may be redelivered after a crash
between dispatch and mark-dispatched).

---

## Installation & wiring

1. **Enable the plugin** (publishes config/, database/, resources/):

   ```bash
   hkm plugins enable User
   ```

2. **Register the Provider** in your project bootstrap:

   ```php
   // projects/<name>/bootstrap/app.php
   return $builder
       ->withModules([
           Plugins\Crypto\Provider::class,   // crypto.services (HashingPort)
           Plugins\View\Provider::class,     // view.rendering
           Plugins\User\Provider::class,     // user.management
       ])
       ->build();
   ```

3. **Ensure required capabilities are available:** `database.management`
   (DatabaseConnectionManagerContract — the repository pins to the central/default
   connection), `crypto.services` (HashingPort), `cache.redis` (CachePort),
   `view.rendering` (ViewRendererContract). The plugin declares these in
   `requires[]`, so boot fails fast if one is missing.

4. **Run migrations:**

   ```bash
   php cli migrate:run
   php cli db:seed --class=UserSeeder    # optional baseline admin
   ```

5. **Schedule the outbox relay** (e.g. cron every minute):

   ```cron
   * * * * * cd /app && php cli user:outbox:relay --limit=500
   ```

---

## Configuration

`.env` keys (all optional):

```ini
HASH_BCRYPT_COST=12        # bcrypt cost (crypto.services)
CSRF_BIND_COOKIE=csrf_bind # must match the CsrfTokenLayer bindCookie
CSRF_LIFETIME=43200        # must match the CsrfTokenLayer lifetime (seconds)
```

`module.json` declares `requires`, `routes`, `views`, `emits`, `commands`, and
`config[]`. Every env var the plugin reads is declared in `config[]` (boot fails
otherwise).

---

## Using it from another plugin

Depend on the **published contract**, never on internals. Declare the domain in
your `module.json`:

```jsonc
// plugins/Billing/module.json
{ "requires": ["user.management", "database.management"] }
```

Inject the contract in your Provider:

```php
use Plugins\User\API\Contracts\UserServiceContract;

$container->bind(InvoiceService::class, fn($c) => new InvoiceService(
    users: $c->make(UserServiceContract::class),   // resolvable because you require user.management
));
```

```php
final class InvoiceService
{
    public function __construct(private readonly UserServiceContract $users) {}

    public function billFor(string $userId): void
    {
        $user = $this->users->find($userId);     // ?UserDTO — primitives only
        if ($user === null) { /* … */ }
    }
}
```

React to user lifecycle events instead of polling — subscribe in `boot()`:

```php
public function boot(HttpPipeline $http, CliPipeline $cli, WorkerPipeline $w, EventBus $events): void
{
    $events->subscribe('user.registered', SendWelcomeEmailListener::class);
    $events->subscribe('user.deleted',    PurgeUserDataListener::class);
}
```

The event payload is **primitives only** (`userId`, `username`, `email`,
`occurredAt`, `version`) — your module never needs the User plugin's value
objects.

> Scope isolation still applies: requiring `user.management` grants the **public
> contract** only. `UserRepository` and other `bindInternal` bindings throw
> `ScopeViolationException` if resolved cross-scope.

---

## Using it from a project

A project route can opt into the plugin without loading it app-wide:

```jsonc
// projects/<name>/proj.json
{ "routes": [
    { "method": "GET", "path": "/admin/users",
      "handler": "Projects\\Admin\\Http\\AdminUserController@index",
      "requires": ["user.management"], "filters": ["auth"] }
] }
```

```php
namespace Projects\Admin\Http;

use Plugins\User\API\Contracts\UserServiceContract;
use Plugins\User\API\DTOs\ListUsersQuery;
use Project\Http\Controllers\ApiController;

final class AdminUserController extends ApiController
{
    public function __construct(private readonly UserServiceContract $users) {}

    public function index(): Response
    {
        $page = $this->users->list(ListUsersQuery::fromRequest($this->resolveRequest()));
        return $this->paginated(array_map(fn($u) => $u->toArray(), $page->items),
                                total: count($page->items), page: 1, perPage: $page->limit);
    }
}
```

Project views override plugin views by default (project-first cascade); target a
specific plugin view with `user::users/index`.

---

## CLI

```bash
php cli user:outbox:relay            # relay up to 100 pending events
php cli user:outbox:relay --limit=500
```

Returns the number dispatched. Idempotent and safe to run concurrently (rows are
claimed via a status guard).

---

## Testing

The service depends on the `UserStore` and `OutboxPort` **interfaces** (DIP), so
it is fully unit-testable with in-memory fakes — no database:

```php
$svc = new UserService(
    repository:  new FakeUserStore(),
    transaction: new TransactionManager(new FakeDatabasePort()),
    collector:   new DomainEventCollector(),
    outbox:      new FakeOutbox(),
    hasher:      new FakeHasher(),
    identity:    Identity::asAdmin(),
    cache:       new FakeCache(),
    audit:       new AuditLogger('admin', fn($l) => null),
);
```

See `tests/Unit/Plugins/User/`: identity (registration, duplicate rejection,
weak-password rejection, authorization, update/delete events, login lockout,
rehash-on-login), `FeedbackServiceTest` (auth/ownership/triage, forward-only
status, rating validation), and `UserSettingsServiceTest` (all four settings,
round-tripped through the real repository against a stateful in-memory DB). Run:

```bash
vendor/bin/phpunit tests/Unit/Plugins/User
```

---

## Extending the pattern

This plugin is a template. To build your own domain module the same way:

1. **module.json** — declare `solves`, `requires`, `exposes`, `routes`, `emits`,
   `config`. One module, one domain.
2. **Domain** — `final` entity with a **private constructor** + named
   constructors (`create`/`reconstitute`); value objects for every concept;
   record domain events in the aggregate. Zero external imports.
3. **API** — a published `…ServiceContract` interface + DTOs (validation in
   `fromRequest`) + primitives-only integration events.
4. **Application** — a service that owns the `TransactionManager`, collects
   domain events, writes integration events to an **outbox** in-tx, and does
   **authorization first**.
5. **Infrastructure** — repository (DatabasePort only, optimistic-locked;
   control-plane repos pin to the central connection), gateways (vendor SDK
   only), thin controllers (≤3 lines).
   Hide concretes behind **internal ports** so the service stays testable.
6. **Provider** — `register()` binds internals with `bindInternal` and the
   contract with `bind`; `boot()` registers hooks / CLI commands / event
   subscriptions.

Copy `plugins/User/` as a starting skeleton, rename the namespace, and replace
the domain.

---

*Part of the AlfacodeTeam PhpServicePlatform. See the root `CLAUDE.md` and
`docs/ai-context/` for framework-wide architecture.*

## Tenant profile reads — `TenantProfileReaderContract` (published)

`TenantProfileProvisioner` implements the published
`TenantProfileReaderContract` — `fullName(userId, tenantId): string` — in two
construction modes: **pinned** (repository already built against the resolved
tenant connection; the listener path) or **resolver** (container binding;
resolves the tenant DB per call through Tenancy's
`TenantConnectionResolverContract`). Reads are best-effort and never throw: a
missing profile or unreachable tenant DB yields `''`. Consumers: Tenancy's
tenant-selection flow (the JWT `name` claim) and `UserService::find()` (attaches
`UserDTO.fullName` when a membership pins the tenant). `UserDTO` also carries
`avatarUrl` and `permissions`. `UserServiceContract::find()` accepts
`bool $isAuth = false` — issuance-time lookups by Auth skip the
self-or-permission check (the request Identity is still guest during login).
