# OAuth2 Plugin — `Plugins\OAuth2`

> Native **OAuth 2.1 + OpenID Connect** authorization server for the AlfacodeTeam
> PhpServicePlatform (HKM Kernel). No vendor OAuth stack — it issues **platform
> JWTs** that the Auth plugin's `JwtAuthLayer` already verifies, so an issued
> access token authenticates against the existing SecurityGateway with zero extra
> wiring.

- **solves:** `oauth.server`  ·  **type:** module (on-demand)
- **requires:** `database.management`, `crypto.services`, `user.management`, `view.rendering`
- **exposes:** `ClientStore`, `AuthorizationFlow`

---

## Table of contents

1. [What it does](#what-it-does)
2. [Architecture](#architecture)
3. [Setup](#setup)
4. [Configuration (env)](#configuration-env)
5. [All routes](#all-routes)
6. [Grants & flows](#grants--flows)
   - [Authorization Code + PKCE (browser)](#1-authorization-code--pkce-browser)
   - [First-party native / mobile (in-app login & registration)](#2-first-party-native--mobile-in-app-login--registration)
   - [Client Credentials (machine-to-machine)](#3-client-credentials)
   - [Refresh Token](#4-refresh-token)
   - [Password (legacy)](#5-password-legacy)
   - [Device Code (RFC 8628)](#6-device-code)
7. [Token endpoint reference](#token-endpoint-reference)
8. [UserInfo, Introspection, Revocation](#userinfo-introspection-revocation)
9. [Discovery & JWKS](#discovery--jwks)
10. [Scopes](#scopes)
11. [Client management](#client-management)
12. [Admin UI & PKCE simulator](#admin-ui--pkce-simulator)
13. [CLI commands](#cli-commands)
14. [Security model](#security-model)
15. [Troubleshooting](#troubleshooting)

---

## What it does

Grants supported (all through one server):

| Grant | Use case |
|---|---|
| `authorization_code` (+ PKCE) | Web apps, SPAs, mobile — the modern default |
| `client_credentials` | Machine-to-machine, no user |
| `refresh_token` | Silent renewal (rotation + reuse-detection) |
| `password` | First-party legacy (discouraged by OAuth 2.1) |
| `urn:ietf:params:oauth:grant-type:device_code` | TVs, CLIs, IoT (RFC 8628) |

Plus: **OIDC** (`id_token`, `/userinfo`, discovery), **JWKS**, **introspection**,
**revocation**, a **self-service** client/token API, a **tenant-wide admin** API +
Pageflow dashboard, and a **PKCE simulator**.

Access tokens are signed JWTs (**HS256** by default, **RS256** recommended and used
in the reference setup) carrying `sub`, `aud` (resource),
`azp` (client), and `scope:*` entries in `permissions`. They're verified by the
Auth plugin's `JwtAuthLayer`; revocation works via the refresh-token family +
a JWT `jti` deny-list.

---

## Architecture

- **GDA layered** — `Domain/` (Client, AuthCode, RefreshToken, DeviceCode, Pkce,
  GrantType), `Application/` (services + ports), `Infrastructure/` (repositories,
  HTTP controllers, CLI, identity adapters).
- **Ports (published):** `ClientStore`, `AuthorizationFlow` (headless code
  issuance for first-party flows). Internal ports: `AuthCodeStore`,
  `RefreshTokenStore`, `ScopeStore`, `DeviceCodeStore`, `ResourceOwnerVerifier`,
  `UserInfoProvider`.
- **Storage** — every repository binds the **per-request `DatabasePort`**, so the
  server tables follow the request connection: **tenant-scoped under Tenancy,
  central in a single-DB deployment.** Tables: `oauth_clients`,
  `oauth_auth_codes`, `oauth_refresh_tokens`, `oauth_scopes`, `oauth_device_codes`.
  They ship in **`database/tenant-template/`** (apply with `tenant:migrate`), not
  the project migrate path.
- **Signing** — `TokenIssuer` signs with the platform JWT key
  (`JWT_PRIVATE_KEY(_FILE)`, algo `JWT_ALGO`). Verification key is the public key,
  published at `/oauth/jwks`.

---

## Setup

### 1. Register the plugin

Add the provider to your project bootstrap `withModules([...])`:

```php
Plugins\OAuth2\Provider::class,   // solves: oauth.server
```

Requires `database.management`, `crypto.services`, `user.management`,
`view.rendering` to be present (they are, in a standard project). The Pageflow
admin/consent pages additionally need `http.pageflow` — the routes declare it via
`requires: ["http.pageflow"]`, so just make sure the Pageflow plugin is enabled.

### 2. JWT keys (RS256 recommended)

Generate an RSA keypair and point env at it:

```bash
mkdir -p storage/keys
openssl genpkey -algorithm RSA -pkcs8 -out storage/keys/oauth-private.pem
openssl rsa -in storage/keys/oauth-private.pem -pubout -out storage/keys/oauth-public.pem
```

```dotenv
JWT_ALGO=RS256
JWT_PRIVATE_KEY_FILE=/abs/path/storage/keys/oauth-private.pem
JWT_PUBLIC_KEY_FILE=/abs/path/storage/keys/oauth-public.pem
JWT_KID=my-oauth-1
JWT_ISSUER=https://your-host
JWT_AUDIENCE=https://your-host/api
```

> The **same** keys must be used by the Auth `JwtAuthLayer` in `withSecurity([...])`
> so issued tokens verify. HS256 works too (`JWT_ALGO=HS256`, `JWT_SECRET=…`).

⚠️ **File permissions:** if you serve via nginx + PHP-FPM (www-data), FPM must be
able to **read** the private key, or the token endpoint 500s with *"OpenSSL unable
to validate key"*. Grant group/ACL read (e.g. `chmod 640` when www-data shares the
owner's group, or `setfacl -m u:www-data:r …`).

### 3. Create the tables

Server tables are tenant-template migrations:

```bash
hkm tenant:migrate           # applies to every active tenant DB
# single-DB (no Tenancy)? run your normal migrate against the default connection
```

### 4. Seed the scope catalogue (optional but recommended)

Requested scopes are validated against `oauth_scopes`. Seed some (or use the admin
UI → Scopes, or `POST /oauth/admin/scopes`):

```sql
INSERT INTO oauth_scopes (id, description, created_at) VALUES
  ('profile','View your profile', NOW()),
  ('email','View your email', NOW()),
  ('read','Read your data', NOW()),
  ('write','Create and update content', NOW());
```

> An **empty** `scope` request falls back to the client's registered scopes and
> skips the catalogue check — so the flow works before you seed anything.

### 5. CSRF exemptions (token/JSON endpoints)

The machine/token endpoints must bypass the browser CSRF layer. In your project's
`withSecurity([... new CsrfTokenLayer(exemptPaths: [...]) ...])`, exempt:

```
/oauth/token /oauth/introspect /oauth/revoke /oauth/device_authorization
/oauth/clients /oauth/authorized-tokens /oauth/admin
```

The browser **consent** form (`/oauth/authorize` POST) stays protected.

### 6. Admin access

Admins are identified by role or an allowlist:

```dotenv
OAUTH_ADMIN_ROLE=admin          # a caller holding this role is admin
OAUTH_ADMIN_USERS=5,42          # …or an explicit user-id allowlist
```

---

## Configuration (env)

| Key | Default | Meaning |
|---|---|---|
| `OAUTH_ACCESS_TTL` | `3600` | Access-token lifetime (s) |
| `OAUTH_REFRESH_TTL` | `1209600` | Refresh-token lifetime (s, 14d) |
| `OAUTH_CODE_TTL` | `60` | Authorization-code lifetime (s) |
| `OAUTH_DEVICE_TTL` | `600` | Device-code lifetime (s) |
| `OAUTH_DEVICE_INTERVAL` | `5` | Device polling interval (s) |
| `OAUTH_TOKEN_AUDIENCE` | `JWT_AUDIENCE` | Access-token `aud` |
| `OAUTH_ADMIN_ROLE` | `admin` | Role granting admin API access |
| `OAUTH_ADMIN_USERS` | — | Comma-separated admin user-id allowlist |
| `JWT_ALGO` / `JWT_PRIVATE_KEY(_FILE)` / `JWT_PUBLIC_KEY(_FILE)` / `JWT_KID` / `JWT_ISSUER` / `JWT_AUDIENCE` | — | Signing/verification (shared with Auth) |

---

## All routes

Everything below is live. `auth` = requires a valid Bearer/session Identity;
`admin` = `auth` **plus** the OAuth admin check.

> **Response formats.** The **OAuth/OIDC spec endpoints** (`/oauth/token`,
> `/oauth/introspect`, `/oauth/revoke`, `/oauth/device*`, `/oauth/jwks`, discovery)
> return **raw RFC-shaped JSON**. The **management API** (`/oauth/scopes`,
> `/oauth/clients*`, `/oauth/authorized-tokens*`, `/oauth/admin/*`) returns the
> platform envelope **`{ "data": … }`** on success and `{ "error": { code, message } }`
> on failure. Examples below show the payload inside `data`.

### Core OAuth / OIDC

| Method | Path | Auth | Purpose |
|---|---|---|---|
| GET | `/oauth/authorize` | session | Consent screen (Pageflow) — starts auth-code flow |
| POST | `/oauth/authorize` | session + CSRF | Consent decision (approve/deny) → redirect with `code` |
| POST | `/oauth/token` | client | Token endpoint (all grants) |
| POST | `/oauth/device_authorization` | client | Device grant start → `device_code` + `user_code` |
| GET/POST | `/oauth/device` | session | Device verification screen (enter `user_code`) |
| GET | `/oauth/userinfo` | Bearer | OIDC UserInfo (`sub`, …) |
| POST | `/oauth/introspect` | client | RFC 7662 token introspection |
| POST | `/oauth/revoke` | client | RFC 7009 token/family revocation |
| GET | `/oauth/jwks` | none | Public signing keys (JWK Set) |
| GET | `/oauth/scopes` | none | Grantable scope catalogue |
| GET | `/.well-known/oauth-authorization-server` | none | RFC 8414 metadata |
| GET | `/.well-known/openid-configuration` | none | OIDC discovery |

### Self-service (owner-scoped — the caller's own clients/grants)

| Method | Path | Auth | Purpose |
|---|---|---|---|
| GET | `/oauth/clients` | auth | List **my** clients |
| POST | `/oauth/clients` | auth | Register a client (secret shown once) |
| PUT | `/oauth/clients/{id}` | auth | Update my client |
| DELETE | `/oauth/clients/{id}` | auth | Revoke my client |
| GET | `/oauth/authorized-tokens` | auth | Apps **I** authorized |
| DELETE | `/oauth/authorized-tokens/{id}` | auth | Revoke one of my grants |

### Admin (tenant-wide)

| Method | Path | Auth | Purpose |
|---|---|---|---|
| GET | `/oauth/admin` | admin (session) | Pageflow admin dashboard |
| GET | `/oauth/admin/simulate` | admin (session) | Per-client OAuth/PKCE simulator |
| GET | `/oauth/admin/clients` | admin | **All** clients (with owner) |
| POST | `/oauth/admin/clients` | admin | Create a client |
| PUT | `/oauth/admin/clients/{id}` | admin | Update any client |
| POST | `/oauth/admin/clients/{id}/rotate` | admin | Rotate any client's secret |
| DELETE | `/oauth/admin/clients/{id}` | admin | Revoke any client |
| GET | `/oauth/admin/scopes` | admin | Scope catalogue |
| POST | `/oauth/admin/scopes` | admin | Add a scope |
| DELETE | `/oauth/admin/scopes/{id}` | admin | Remove a scope |
| GET | `/oauth/admin/authorized-tokens` | admin | **All** users' active grants |
| DELETE | `/oauth/admin/authorized-tokens/{id}` | admin | Revoke any grant |

> The first-party **native login/registration** endpoints live in the **Auth**
> plugin (`/auth/mobile/login`, `/auth/mobile/register`) but drive this plugin's
> `AuthorizationFlow` — see [flow 2](#2-first-party-native--mobile-in-app-login--registration).

---

## Grants & flows

### 1. Authorization Code + PKCE (browser)

The default for web apps, SPAs and mobile using a system browser.

```
# 1) App builds a PKCE pair and sends the user to:
GET /oauth/authorize
    ?response_type=code
    &client_id=<id>
    &redirect_uri=<exact registered uri>
    &scope=profile read            # optional (empty → client scopes)
    &state=<random csrf>
    &code_challenge=<BASE64URL(SHA256(verifier))>
    &code_challenge_method=S256

# 2) Server: requires a login session (else 302 → /login?redirectTo=…),
#    renders the Pageflow consent screen. On "Allow" it 302s back to:
<redirect_uri>?code=<code>&state=<state>

# 3) App exchanges the code (holds the verifier):
POST /oauth/token   (application/x-www-form-urlencoded)
grant_type=authorization_code
&client_id=<id>
&redirect_uri=<same uri>
&code=<code>
&code_verifier=<verifier>          # public clients (PKCE); confidential add secret

→ 200 { "token_type":"Bearer", "access_token":"…", "expires_in":3600,
        "refresh_token":"…", "scope":"profile read", "id_token":"…" (if openid) }
```

Rules: `redirect_uri` is an **exact** match (no wildcards); PKCE is **mandatory
for public clients**; codes are single-use, hashed at rest, 60s TTL.

### 2. First-party native / mobile (in-app login & registration)

For your own Android/iOS app that hosts its **own** login/registration UI — no
browser, no consent screen. The app posts credentials **plus** PKCE params to the
**Auth plugin**, which mints an authorization `code` headlessly via this plugin's
`AuthorizationFlow`, then the app exchanges it here.

```
# Register OR login in-app (Auth plugin):
POST /auth/mobile/register     { email, username, password,
                                 client_id, redirect_uri,
                                 code_challenge, code_challenge_method:"S256",
                                 state, scope }
    → 201 { "code":"…", "state":"…" }

POST /auth/mobile/login        { identifier|email, password, <same PKCE params> }
    → 200 { "code":"…", "state":"…" }
    # omit client_id → legacy path returns { user, tokens } directly

# Then exchange the code for tokens (this plugin):
POST /oauth/token
grant_type=authorization_code & client_id=… & redirect_uri=… & code=… & code_verifier=…

# Password reset (Auth plugin, OTP):
POST /auth/password/forgot     { email }                  → 200 (OTP emailed)
POST /auth/password/verify-otp { email, otp }             → 200 { resetToken }
POST /auth/password/reset      { email, token, password } → 200
```

Requirements: the mobile routes must `requires: ["oauth.server"]` (so the flow
resolves) and be CSRF-exempt; the client's `redirect_uri` (e.g. a custom scheme
`com.app.oauth://callback`) must be registered.

### 3. Client Credentials

Machine-to-machine, no user context.

```
POST /oauth/token
grant_type=client_credentials
&client_id=<id>&client_secret=<secret>       # or HTTP Basic
&scope=reports:read

→ 200 { "token_type":"Bearer", "access_token":"…", "expires_in":3600, "scope":"reports:read" }
# no refresh_token
```

### 4. Refresh Token

Rotation + family reuse-detection: each refresh returns a **new** refresh token
and invalidates the old one; replaying an old token revokes the whole family.

```
POST /oauth/token
grant_type=refresh_token
&refresh_token=<token>
&client_id=<id>                              # + secret for confidential
&scope=read                                  # optional — may only NARROW

→ 200 { access_token, refresh_token (new), expires_in, scope }
```

### 5. Password (legacy)

Discouraged by OAuth 2.1; enable only for trusted first-party clients.

```
POST /oauth/token
grant_type=password
&client_id=<id>&client_secret=<secret>
&username=<u>&password=<p>&scope=…
```

### 6. Device Code

For input-constrained devices (RFC 8628).

```
# Device:
POST /oauth/device_authorization   client_id=<id>&scope=…
→ 200 { device_code, user_code, verification_uri, verification_uri_complete, expires_in, interval }

# User (on a phone/laptop): open verification_uri, GET/POST /oauth/device, enter user_code, approve.

# Device polls:
POST /oauth/token
grant_type=urn:ietf:params:oauth:grant-type:device_code
&device_code=<device_code>&client_id=<id>
→ authorization_pending | slow_down | 200 { access_token, refresh_token, … }
```

---

## Token endpoint reference

`POST /oauth/token` — `application/x-www-form-urlencoded`. **Client authentication**
methods: `client_secret_basic` (HTTP Basic), `client_secret_post` (body), or
`none` (public client identified by `client_id` + PKCE).

| grant_type | Required fields |
|---|---|
| `authorization_code` | `code`, `redirect_uri`, `client_id` (+ `code_verifier` for public, or secret for confidential) |
| `client_credentials` | `client_id` + secret, `scope` |
| `refresh_token` | `refresh_token`, `client_id` (+ secret); optional narrowing `scope` |
| `password` | `client_id` + secret, `username`, `password`, `scope` |
| device code | `device_code`, `client_id` |

Success body: `{ token_type, access_token, expires_in, scope, refresh_token?, id_token? }`.
Errors follow RFC 6749: `{ "error": "...", "error_description": "..." }` (e.g.
`invalid_grant`, `invalid_client`, `invalid_scope`, `unsupported_grant_type`,
`unauthorized_client`).

---

## UserInfo, Introspection, Revocation

```
GET  /oauth/userinfo            Authorization: Bearer <access_token>   → { "sub":"…", … }

POST /oauth/introspect          token=<token>[&token_type_hint=…]
     → { "active":true, "sub","client_id","scope","exp", … } | { "active":false }

POST /oauth/revoke              token=<access_or_refresh>   → 200 { "ok": true }
     # revokes the refresh-token family and deny-lists the access token's jti
```

`/userinfo` returns `sub` by default; a project can bind a richer, scope-aware
`UserInfoProvider`.

---

## Discovery & JWKS

```
GET /.well-known/oauth-authorization-server   # RFC 8414
GET /.well-known/openid-configuration          # OIDC
GET /oauth/jwks                                # public keys clients use to verify JWTs
```

Discovery advertises: endpoints, `grant_types_supported`, `response_types_supported=["code"]`,
`code_challenge_methods_supported=["S256","plain"]`,
`token_endpoint_auth_methods_supported=["client_secret_basic","client_secret_post","none"]`,
and `scopes_supported` (from the catalogue).

---

## Scopes

- `GET /oauth/scopes` — public catalogue `{ "data": { "scopes": [ { "id", "description" } ] } }`.
- Requested scopes must exist in `oauth_scopes` **unless** the request omits scope
  (then the client's own registered scopes are used, no catalogue check).
- Namespaced scopes ride as `scope:*` in the JWT `permissions`, so resource routes
  can gate on them.
- Manage the catalogue via the admin UI or `POST/DELETE /oauth/admin/scopes`.

---

## Client management

### Self-service (`/oauth/clients`, owner-scoped)

```
POST /oauth/clients   Authorization: Bearer <user token>
{ "name":"My SPA", "redirect_uris":["https://app.example.com/callback"],
  "scopes":["profile","read"], "public":true }

→ 201 { "data": { "id":"…", "client_secret":"…"(confidential only, shown ONCE),
                  "redirect_uris":[…], "scopes":[…], "confidential":false } }
```

Public (`"public":true`) → no secret, PKCE required, grants
`authorization_code`+`refresh_token`. Confidential → a secret is issued **once**.

### Admin (`/oauth/admin/clients`, tenant-wide)

Same shape, but sees/edits **every** client and accepts an explicit
`grant_types` list (`authorization_code`, `refresh_token`, `client_credentials`,
`password`, `urn:ietf:params:oauth:grant-type:device_code`). Scopes are validated
against the catalogue. `.../{id}/rotate` returns a fresh secret once.

---

## Admin UI & PKCE simulator

Server-rendered through **Pageflow** (federated plugin UI in `ui/`):

- **`GET /oauth/admin`** — dashboard: all clients (with owner avatar/name/email),
  scope catalogue (add/delete), and every active grant (revoke). Session-gated;
  a guest is bounced to `/login`, a non-admin gets 403. Its JS calls the
  `/oauth/admin/*` JSON API same-origin.
- **`GET /oauth/admin/simulate?client=<id>`** — a per-client **PKCE simulator**
  (offline: verifier → challenge → server-verify demo) **plus** a **real** launch
  that runs the full Authorization Code + PKCE flow against the live server and
  exchanges the code for tokens (uses the page itself as the registered redirect;
  one-click "add redirect & enable" if missing).

The UI ships in `plugins/OAuth2/ui/` (`ui.json`, `admin/Pages/OAuth2/{Admin,Simulate,Consent}.tsx`).
Run `hkm ui sync` + rebuild the frontend to publish changes.

---

## CLI commands

All are **tenant-aware**: pass `--tenant=<id|slug|db>` to target one tenant, or
`--all` for the whole fleet; omit to use the central/default connection.

```bash
hkm oauth:client:create --tenant=acme --public \
    --name="My SPA" --redirect="https://app/callback" \
    --grant=authorization_code,refresh_token --scope="profile read"
hkm oauth:client:list   --tenant=acme      # or --all
hkm oauth:client:revoke --tenant=acme --client=<id>
hkm oauth:client:rotate --tenant=acme --client=<id>   # new secret (confidential)
hkm oauth:prune         --all              # delete expired codes/tokens/device codes
```

Supported `--grant` values: `authorization_code`, `refresh_token`,
`client_credentials`, `password`, `urn:ietf:params:oauth:grant-type:device_code`.

---

## Security model

- **Signing:** RS/ES/PS or HS. Public key at `/oauth/jwks`; verified by Auth's
  `JwtAuthLayer` (issuer/audience bound, clock-skew leeway).
- **PKCE:** mandatory for public clients; S256 preferred; verifier hashed at
  authorize-time, checked timing-safe at token-time.
- **redirect_uri:** exact match only, validated **before** any error is redirected
  (no open-redirect / error harvesting).
- **Refresh rotation + reuse detection:** replay of a rotated token kills the family.
- **Revocation:** refresh-family drop + JWT `jti` deny-list.
- **CSRF:** browser consent form is protected; token/JSON endpoints are exempt (they
  authenticate by client credentials / PKCE / Bearer, not cookies).
- **Tenant isolation:** all server data resolves the per-request `DatabasePort`.

---

## Troubleshooting

| Symptom | Cause / fix |
|---|---|
| `500 "OpenSSL unable to validate key"` on `/oauth/token` | FPM (www-data) can't read the private key. `chmod 640` / ACL the key + traverse its dir. |
| `400 redirect_uri does not match a registered URI` | The `redirect_uri` sent isn't **exactly** one registered on the client. Register it (admin/console). |
| `400 invalid_scope` | Requested a scope not in `oauth_scopes`. Seed it, or send no `scope`. |
| `500 "Table … oauth_* doesn't exist"` | Run `tenant:migrate` (tables are tenant-scoped) against the DB your host resolves to. |
| `403 "CSRF token missing"` on a JSON/token call | Add that path to the `CsrfTokenLayer` `exemptPaths`. |
| `403 "Administrator access required"` | Add your user id to `OAUTH_ADMIN_USERS` (or grant `OAUTH_ADMIN_ROLE`). |
| Route `404` after editing `module.json` / `proj.json` | Manifests are cached — `rm var/cache/manifests/*.php` to rebuild (nginx+FPM). Also check `proj.json` `routePolicy.disable` didn't veto it. |
| Admin/consent page blank | The Pageflow plugin UI needs `hkm ui sync` + a frontend rebuild. |
| `crypto.subtle is undefined` in the simulator | Insecure context (plain `http://host`) — the shipped page uses a pure-JS SHA-256 fallback; rebuild the frontend. |

---

_Part of the HKM Kernel. See also: `docs/ai-context/26_OAUTH2.md`,
`docs/ai-context/25_AUTH.md` (JWT/session/PAT), `docs/ai-context/23_TENANCY.md`._
