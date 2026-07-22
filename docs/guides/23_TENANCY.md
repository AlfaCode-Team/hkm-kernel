# Tenancy Plugin — Multi-Tenant Control Plane

> AI reference for `Plugins\Tenancy\` (solves `tenancy.routing`, **essential**).
> Database-per-tenant isolation + central control plane on top of
> `plugins/Database`'s `ConnectionManager`. Pairs with [09_SECURITY](09_SECURITY.md),
> [19_DATABASE](19_DATABASE.md), [24_USER](24_USER.md).

---

## WHAT IT DOES

Maps an incoming request to **one tenant**, then rebinds `DatabasePort` to that
tenant's **isolated database** for the request, so every repository downstream
transparently talks to the right DB. The control-plane tables (tenant registry,
memberships, invitations, hosts, audit) live in the **central**
database and are NEVER tenant-routed.

```
Request → identify tenant → resolve isolated DatabasePort → rebind for this request
          (claim / host / cookie)   (registry + breaker, fail-closed)
```

`requires: ["database.management"]` — module-level requires cover ONLY the
always-on `TenantContextStage` path. Everything the selection / admin /
invitation / host ROUTES need (`auth.identity`, `user.management`,
`audit.trail`, `http.pageflow`) is declared per route in `module.json`
`routes[].requires`, so a Tenancy-essential project does not register those
modules on every request.

---

## TENANT IDENTIFICATION — `TENANCY_MODE`

The pluggable `TenantIdentifier` seam decides WHICH tenant a request belongs to.
`identify(Request): string` returns the tenant id, or `''` when none was
identified — which the stage FAILS CLOSED on (404). It may also throw
`UnknownTenantException` to refuse a host explicitly (same 404).

| Mode (`TENANCY_MODE`) | Identifier | Tenant source |
|---|---|---|
| `claim` (default, SaaS) | `ClaimTenantIdentifier` | `Identity.tenantId` (the signed JWT `tnt` claim) |
| `domain` (storefront) | `DomainTenantIdentifier` | Host sub-domain under `TENANCY_BASE_DOMAINS` |
| `host` (custom domains) | `HostTenantIdentifier` | FULL hostname via the central `tenant_hosts` registry |

**STRICT routing — no unscoped passthrough.** Every request must resolve to a
tenant: cookie hint first, then the identifier; both empty ⇒ **404** (`Tenant
not found`). Every host the app serves must therefore be assigned to a tenant
(`tenant:host:add` in host mode; a resolvable label in domain mode). Central
control-plane code never depends on the stage skipping the rebind — it pins the
central connection explicitly via the `ConnectionManager` default.

**Activation — must be ESSENTIAL, declared by the PROJECT.** `TenantContextStage`
is an always-on `after.load` hook that resolves `TenantIdentifier` + the
connection resolver from the **request container**; those bindings only exist
when `Tenancy::register()` ran, and the stage now FAILS LOUDLY when they are
absent. A multi-tenant project declares `"essentials": ["tenancy.routing"]` in
its `proj.json` (read by `EntryHelpers::projectEssentials()` →
`Kernel::withEssentialModules()`, which also accepts domains and fails the boot
on an unknown one). A single-tenant project leaves Tenancy OUT of `withModules`
entirely — merely dropping it from essentials would make the always-on stage
throw on every request. Essentials resolve through the dependency graph, so
Tenancy's `database.management` requirement loads with it automatically.

**`domain` mode + session login — cross-subdomain cookie.** Control-plane routes
(`/auth/login`, `/ajx/me/tenants`, `/ajx/tenants/{id}/select`) run on the
apex/central host (`shop.localhost` → `''` → central); tenant-scoped routes run on
`<tenant>.shop.localhost` (→ that tenant's DB). For the apex login's session to
carry to the tenant sub-domains, set the session cookie's domain to the shared
base: `SESSION_COOKIE_DOMAIN=.shop.localhost` (host-only otherwise = 401 on the
sub-domain). Reserved sub-domains (`TENANCY_RESERVED_SUBDOMAINS`: www, api, admin,
…) resolve to central, never a tenant.

---

## REQUEST ROUTING — `TenantContextStage` (after.load, priority 5)

`Infrastructure/Http/Stages/TenantContextStage.php`. Runs after the request
container exists, before route filters / `ExecuteStage`.

1. Resolve the active tenant: **encrypted cookie hint first** (principal-bound),
   then the `TenantIdentifier` (see cookie section). Both empty → **404 fail
   closed** — there is NO unscoped passthrough to the central `DatabasePort`.
2. `resolver->for($tenantId)` → isolated `DatabasePort` (registry lookup +
   per-tenant circuit breaker; **fail-closed**, no silent fallback).
3. `$container->instance(DatabasePort::class, $db)` — rebind for THIS request only.
4. `$request->withAttribute('tenant', $tenantId)` — expose to controllers.
5. `$container->bind('tenant.current', fn() => $tenantId)` — a **plain string
   container key** so request-scoped services that never see the `Request` (e.g.
   the User `AuditLogger`) can read the active tenant with no Tenancy import.
   Use `bind()` (closure), NOT `instance()`: the kernel `ModuleContainer::instance()`
   requires an `object`, so binding the bare tenant-id string there throws a
   `TypeError` on every host/domain-routed request.
6. On `UnknownTenantException` → 404 (and forget a stale cookie hint); on
   `TenantUnavailableException` → 403/410/503; connectivity faults feed the breaker.

```
✗ Binding tenant context into CoreContainer — it rides the request + request container only (Swoole-safe)
✗ Reading $_SERVER for the host inside a module — use $request->attribute('tenant')
✗ Silent fallback to central or another tenant on resolution failure — fail closed
```

### Tenant cookie (encrypted hint — never authority)

`TenantContextStage` writes an **encrypted, user-bound** cookie remembering the
active tenant so a returning user keeps their selection without re-running the
picker. Properties:

- **Encrypted** via the Cookie plugin's `EncryptionPort` (tamper → `read()` returns null).
- **Principal-bound**: stores `{t: tenantId, u: userId}`; honoured only by the
  exact principal that minted it — a user's hint never replays onto another user
  (or a post-logout guest), while a guest-minted hint (`u` = `''`) keeps working
  for guests so public pages retain their selection. Log-in flips the principal
  and re-mints.
- **Cookie first**: the remembered selection is consulted BEFORE the identifier;
  the identifier only runs when there is no valid hint. Every hint is still
  fully re-validated below, so a stale/hostile value can never route to an
  unknown tenant.
- **Still revalidated** every request through `resolver->for()` — a hint, exactly
  like the `tnt` claim. A stale hint at a deleted tenant is auto-forgotten.

---

## PUBLISHED CONTRACTS (`exposes`)

| Contract | Role |
|---|---|
| `TenantRegistryContract` | tenant_id → connection coordinates (CachePort-cached) |
| `TenantConnectionResolverContract` | `for($tenantId): DatabasePort` (+ breaker) |
| `MembershipServiceContract` | `myTenants`, `isActiveMember`, `selectTenant` |
| `InvitationServiceContract` | email invite → seat (`invite`, `accept`) |
| `TenantHostRegistryContract` | hostname → tenant_id resolution |
| `TenantHostServiceContract` | `add`/`verify`/`makePrimary`/`remove` custom hosts |

Internal ports (`Application/Ports/`): `MembershipReader`/`MembershipWriter`,
`InvitationStore`, `TenantHostStore`, `AuditSink` (write),
`AuditReader` (read), `DnsResolver`.

---

## CENTRAL TABLES (control plane — never in a tenant DB)

| Table | Repository | Notes |
|---|---|---|
| `tenants` | `TenantRegistry` | registry; `db_password_enc` encrypted via `EncryptionPort` |
| `user_tenants` | `MembershipRepository` | M:N user↔tenant + role/status; FK → central `users`/`tenants` |
| `tenant_invitations` | `InvitationRepository` | email onboarding, hashed token |
| `tenant_hosts` | `TenantHostRepository` | PK is **`host_id`** (not `id`); custom domains + DNS verify |
| `audit_log` | write `AuditTrail` / read `AuditLogRepository` | append-only; keyset-paginated reads |

Migrations: `plugins/Tenancy/database/migrations/`. Tenant template schema (run
per new tenant DB): `plugins/Tenancy/database/tenant-template/` (or
`TENANCY_TEMPLATE_PATH`). See [18_MIGRATIONS](18_MIGRATIONS.md).

---

## AUDIT TRAIL (`audit_log`)

Shared central table written by BOTH Tenancy and the User plugin.

- **Write**: `AuditSink::record(action, userId?, tenantId?, meta[], ip?)` →
  `AuditTrail` (best-effort — an audit write NEVER breaks the audited action).
- **Read**: `AuditReader` → `AuditLogRepository` — `recent`, `forTenant`,
  `forUser`, `byAction` (keyset-paginated by descending id), `find(eventId)`,
  `countForTenant`, `purgeOlderThan(cutoff)` (retention/GDPR). LIMIT is clamped +
  **inlined as an int** (cannot be bound with emulated prepares off); filter
  values stay parameter-bound.

---

## MEMBERSHIP & SELF-SIGNUP ASSIGNMENT

A new user is assigned to their originating tenant via the **`user.registered`**
integration event (User's transactional outbox, relayed by `user:outbox:relay`):

```
self-signup on tenant host → RegisterUserDTO reads request 'tenant' attribute
  → UserRegisteredIntegrationEvent carries tenantId (persisted in the outbox)
  → Tenancy's AssignTenantMembershipOnUserRegistered listener (subscribed in boot())
  → MembershipWriter::upsertActive(userId, tenantId, 'member')   [idempotent]
```

- The listener resolves from the **CoreContainer** (no request context) — so the
  tenant MUST ride on the event payload, never re-derived at relay time.
- The project binds the listener in the CoreContainer with a central-connection
  `MembershipWriter` (the EventBus resolves listeners there). See [08_EVENTS](08_EVENTS.md).
- Assignment is **eventually consistent** (lands when the relay runs) and
  **idempotent** (`upsertActive` upserts on `(user_id, tenant_id)`).

---

## CLI COMMANDS (claim mode only — registered in `Provider::boot()`)

Registered via a deferred closure that builds a scoped `ModuleContainer`
(Database + Crypto + Tenancy) so commands with module-scoped deps resolve. Hidden
in `domain` mode (tenants are provisioned by the project's own tooling there).

| Command | Purpose |
|---|---|
| `tenant:create` | Provision: registry row → CREATE DATABASE → DB user + grant → template migrations → activate. Interactive wizard (RadioGroup driver picker, masked Password, NumberInput port) when flags are missing. **Compensating rollback** on any failure (DDL isn't transactional on MySQL). |
| `tenant:delete` | Drop the tenant DB user (all hosts), optionally the database (`--drop-database`), and the registry row. Requires confirmation / `--yes`. |
| `tenant:host:add` | Register a hostname (via `TenantHostService`); `--verified` seeds it past DNS, `--primary` makes it canonical. Prompts (tenant Select, host, IP Select) for anything omitted in a terminal. |
| `tenant:migrate` | Run tenant template migrations across the fleet (per-tenant transactional, failure-isolated, resumable). |

### Tenant DB user provisioning (driver-aware, `ManagesTenantDatabase` trait)

- **Privileges are scoped to the tenant's database only** — `GRANT ALL ON \`db\`.*`
  (MySQL) / database `OWNER` (pgsql) / `db_owner` (sqlsrv). Never global.
- **MySQL accounts are loopback-only by default** — created at `localhost`,
  `127.0.0.1`, `::1` (works over socket AND TCP); a non-loopback host pins to that
  exact host. **The `'%'` wildcard is never used.**
- Supported: `mysql`/`mariadb`, `pgsql`, `sqlsrv`. `sqlite` is rejected (no
  users/CREATE DATABASE — provision file-per-tenant instead).

---

## TENANT SELECTION & TOKENS (HTTP, `/ajx/...`)

- `GET /ajx/me/tenants` → list my tenants. `POST /ajx/tenants/{id}/select` →
  re-verifies membership, mints a `tnt`-scoped access JWT.
- DECOMPOSED (tenancy ≠ authentication): `MembershipService` is control plane
  ONLY — `selectTenant()` verifies the seat + audits and returns the verified
  `TenantSummary`; it has NO Auth dependency. `TenantController` is the
  composition point: it mints the token via `AuthServiceContract` (with
  `roles` and the `name` claim read through User's published
  `TenantProfileReaderContract`) and builds the `TenantSelection` response.
  This also keeps the container graph acyclic (AuthService → UserService →
  MembershipService — no cycle back into Auth).
- `POST /ajx/invitations/accept` → join a tenant from an emailed invite.
- Refresh-token rotation is NOT here — it moved to `Plugins\Auth` (`POST /auth/refresh`). Tenancy re-checks the tenant seat only at tenant-SELECT.
- Custom hosts: `GET/POST /ajx/tenant/hosts`, `…/{hostId}/verify|primary`, DELETE.

The signed `tnt` claim is a **hint, not authority** — authorization still keys on
`(userId, tenantId, role/permission)` and membership is re-checked each request so
a revoked seat loses access before token expiry.

---

## ABSOLUTE RULES

```
✓ Control-plane tables (tenants, user_tenants, invitations, hosts, audit_log) are CENTRAL — pin to ConnectionManager default. (refresh_tokens now belongs to Plugins\Auth.)
✓ TenantContextStage rebinds DatabasePort per request ONLY; never into CoreContainer.
✓ Tenant DB users: privileges scoped to their own database; MySQL accounts loopback/host-pinned, never '%'.
✓ Membership assignment travels on the user.registered event payload (outbox), idempotent upsert.
✓ Mint a tenant-scoped token ONLY after verifying membership; re-check every request.
✗ Reading $_SERVER / re-identifying the tenant inside a module — use $request->attribute('tenant').
✗ Trusting the tnt claim or tenant cookie as authority — both are revalidated hints.
✗ Hand-writing CREATE USER with '@%' or cross-DB privileges in provisioning.
✗ Binding the membership/audit listener WITHOUT the project supplying its central writer in CoreContainer.
```
