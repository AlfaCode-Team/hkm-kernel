# Tenancy — Multi-Tenant Control Plane

Database-per-tenant routing for the AlfacodeTeam PhpServicePlatform. Maps the
authenticated `Identity.tenantId` to an **isolated tenant database** and rebinds
`DatabasePort` per request, so every repository transparently talks to the
correct tenant DB. Built on top of `plugins/Database`'s `ConnectionManager`.

- **solves:** `tenancy.routing`
- **requires:** `database.management`, `auth.identity`
- **exposes:** `TenantRegistryContract`, `TenantConnectionResolverContract`, `MembershipServiceContract`, `InvitationServiceContract`

## Two planes

| Plane | DB | Tables |
|---|---|---|
| Control (central) | one, always connected | `users`, `tenants`, `user_tenants` (+ invitations/audit) |
| Data (per tenant)  | one per tenant, on demand | pure business domain — `projects`, `tasks`, … **no auth, no `tenant_id` column** |

## Wiring

1. **Run central migrations** (against the central connection):
   ```
   hkm migrate:run        # creates tenants, user_tenants (users lives in plugins/User)
   ```

2. **Register as an ESSENTIAL module** so every request is routed. In
   `projects/<name>/bootstrap/app.php`:
   ```php
   ->withEssentialModules([
       \Plugins\Database\Provider::class,   // database.management (required dep)
       \Plugins\Tenancy\Provider::class,    // tenancy.routing
       // ... Crypto (EncryptionPort), RedisCache (CachePort) must also be available
   ])
   ```
   `EncryptionPort` (Crypto) and `CachePort` (RedisCache) are dependencies of the
   resolver/registry — ensure both are wired.

3. **Mint a tenant-scoped Identity** in your Auth layer. After the user selects a
   tenant, re-check `user_tenants` and put the tenant in the JWT `tnt` claim; the
   Auth security layer sets `Identity.tenantId` from it. `TenantContextStage`
   (registered at `after.load`) does the rest.

   > The membership re-check on every request lives in the Auth layer, not here —
   > a revoked `user_tenants` row must drop access before the JWT expires.

## Control-plane tables

Central migrations (run on the central connection via `hkm migrate:run`):

| Table | Role |
|---|---|
| `tenants` | registry → connection coordinates (password encrypted) |
| `user_tenants` | M:N membership: user ↔ tenant + role + status |
| `tenant_invitations` | email onboarding; SHA-256 token only; converts to a membership on accept |
| `audit_log` | append-only trail (login, `tenant.switch`, `tenant.create`, …) |

## Tenant-selection flow (`MembershipServiceContract`)

Turns an authenticated but *unscoped* user into a tenant-scoped session. Exposed
as routes (both behind the `auth` filter):

```
GET  /ajx/me/tenants                 → the tenant picker (active seats only)
POST /ajx/tenants/{tenantId}/select  → re-mint a tenant-scoped token
```

`selectTenant()` **re-verifies** the membership against central `user_tenants`
(never trusts a client-supplied tenant id), then mints a token via the Auth
module with the `tnt` claim set, and audits `tenant.switch`:

```php
$selection = $memberships->selectTenant($identity->userId, $tenantId, $request->ip());
// → { token, tokenType: "Bearer", tenantId, role, expiresIn }
```

The client sends the returned token on subsequent requests; `TenantContextStage`
routes them to the tenant database. A revoked/suspended seat fails `selectTenant`
with `403` (audited `tenant.switch_denied`) and — because the Auth layer re-checks
membership per request — also loses access on an already-issued token before it
expires. `TENANCY_TOKEN_TTL` (default 3600s) sets the scoped-token lifetime.

## Invitations (`InvitationServiceContract`)

Email-based onboarding that decouples "invited" from "has an account".

```php
$res = $invitations->invite($tenantId, 'alice@example.com', 'member', $inviterUserId);
// → InvitationResult{ token, … }  — embed $res->token in the emailed accept link (shown ONCE)

$tenantId = $invitations->accept($rawToken, $identity->userId, $userVerifiedEmail, $ip);
// validates (pending, not expired, email matches), creates/activates the user_tenants
// seat (idempotent), marks the invite accepted, audits member.join.

$invitations->revoke($rawToken);
```

Only the SHA-256 of the token is stored. `accept()` REQUIRES the authenticated
user's verified email to match the invited address (an invite for alice@ cannot
be claimed by bob@).

Wired endpoint (behind the `auth` filter; the email is read from the User
identity store, never the request body):

```
POST /ajx/invitations/accept   { "token": "…" }   → { "tenantId": "…" }
```

This is why Tenancy `requires: ["user.management"]` — `InvitationController`
resolves the caller's verified email via `UserServiceContract`.

## Refresh tokens — moved to `Plugins\Auth`

Refresh tokens are an **authentication** concern, so they now live in
`Plugins\Auth` (`RefreshTokenServiceContract`, table `refresh_tokens`, endpoints
`POST /auth/refresh` + `/auth/refresh/logout`). See `docs/ai-context/25_AUTH.md`.

The relocated flow is **tenant-agnostic**: `tenantId` rides through as a
passthrough hint for the access token's `tnt` claim but is NOT re-verified on
refresh. The tenant seat re-check (a revoked seat can't get back in) lives HERE,
in the tenant-**selection** flow (`POST /ajx/tenants/{id}/select`), not on refresh.

## Provisioning & migrations

```
hkm tenants:create --name="Acme" --slug=acme \
    --db-name=tnt_acme --db-user=acme --db-password=secret \
    --db-host=127.0.0.1 --db-port=3306

hkm tenants:migrate                 # apply template migrations to all active tenants
hkm tenants:migrate --tenant=<id>   # one tenant
hkm tenants:migrate --pretend       # print SQL, change nothing
```

The tenant template lives in `database/tenant-template/`. Override with
`TENANCY_TEMPLATE_PATH` or `--template`. Each tenant DB keeps its own
`let_migrations` table; the central `tenants.schema_version` mirrors the latest
applied batch for fleet-wide drift visibility. A failing tenant is skipped, not
fatal — the run is resumable.

### `var/tenants.json` — default tenant for the CLI

A successful `tenant:create` records the tenant in the project's
`var/tenants.json` (`Plugins\Tenancy\Support\TenantsFile`) and makes it the
**default** (last created wins). Commands that target one tenant then work
without `--tenant`/`--slug`:

```
hkm tenant:create --name="Acme" --slug=acme ...   # recorded as default
hkm tenant:host:add --host=acme.localhost --verified   # → default tenant
hkm tenant:delete --drop-database                       # → default tenant
```

Tenants provisioned BEFORE this existed (or after a `var/` wipe — it is
disposable) are backfilled with `tenant:remember`:

```
hkm tenant:remember                  # only one tenant registered → recorded; else interactive pick
hkm tenant:remember --slug=acme      # one tenant by slug (becomes the default)
hkm tenant:remember --all            # every registered tenant (last = default)
```

The file is a convenience HINT only — the central `tenants` table stays the
source of truth. Every command re-validates the recorded id against the
registry and silently drops a stale entry (e.g. a tenant deleted elsewhere).
`tenant:delete` also removes the entry on success; the default falls back to
the last remaining recorded tenant. `tenant:migrate` needs no id either way —
it fleet-migrates every active tenant by default.

## Isolation guarantees

- **Fail closed.** Unknown / suspended / deleted / unreachable tenant → throw.
  Never falls back to another tenant or to central. Status is re-validated on
  **every** request (the registry is cache-backed, so it's cheap) — including
  when the connection is already warm in a long-lived worker — so a suspension or
  deletion takes effect within `TENANCY_REGISTRY_TTL`, not "after the next worker
  restart". A control-plane change can call `resolver->invalidate($tenantId)` to
  drop the warm handle + cached row immediately.
- **Per-tenant circuit breaker.** After `TENANCY_BREAKER_THRESHOLD` consecutive
  **connectivity** failures within `TENANCY_BREAKER_WINDOW` seconds, the tenant
  fast-fails for `TENANCY_BREAKER_COOLDOWN` seconds, isolating one dead tenant DB
  from the fleet. Only genuine connection faults (`ConnectionException` with a
  connect / connection_lost / pool_acquire operation) feed the breaker — a bad
  query or domain error does not trip a healthy tenant. The failure counter is a
  sliding window, so sporadic blips never accumulate into a false trip.
- **Swoole-safe.** The resolved tenant `DatabasePort` is bound into the
  per-request `ModuleContainer` and discarded on `reset()`; the tenant id rides
  on the immutable `Request`/`Identity`, never a static or `CoreContainer`.

## Config (`module.json`)

| Env | Default | Meaning |
|---|---|---|
| `TENANCY_MODE` | `tenant` | `legacy` \| `dual-write` \| `tenant` (migration phases) |
| `TENANCY_REGISTRY_TTL` | `60` | registry cache TTL (s) |
| `TENANCY_BREAKER_THRESHOLD` | `5` | connectivity failures before the breaker opens |
| `TENANCY_BREAKER_WINDOW` | `60` | sliding window (s) failures must occur within |
| `TENANCY_BREAKER_COOLDOWN` | `30` | breaker open window (s) |
| `TENANCY_TEMPLATE_PATH` | bundled | tenant template migrations path |

## Swoole connection pooling (optional optimization)

By default `ConnectionManager` is request-scoped, so tenant sockets aren't reused
across requests. For long-lived OpenSwoole workers, bind `ConnectionManager` (and
the resolver) into the **CoreContainer** in bootstrap so resolved tenant adapters
persist per worker. Cap with an LRU eviction of idle tenant connections so a
worker serving thousands of tenants never holds thousands of open sockets — and
front the DB tier with ProxySQL/PgBouncer under PHP-FPM.
