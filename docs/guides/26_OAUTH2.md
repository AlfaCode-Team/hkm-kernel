# OAuth2 Plugin — Authorization Server (OAuth 2.1 + OIDC)

> AI reference for `Plugins\OAuth2\` (solves `oauth.server`).
> A native, dependency-free OAuth 2.1 + OpenID Connect authorization server.
> Access tokens are JWTs signed with the platform JWT keys, so they are verified
> by [25_AUTH](25_AUTH.md)'s `JwtAuthLayer` with no extra wiring. Pairs with
> [24_USER](24_USER.md) (password grant), [09_SECURITY](09_SECURITY.md).

---

## WHAT IT DOES

A full authorization server for **third-party / delegated** access (the piece a
first-party Auth module can't provide). Reuses `firebase/php-jwt` (already a
kernel dep) — no new vendor packages, honouring native distribution.

```
requires: ["database.management", "crypto.services", "user.management", "view.rendering"]
exposes:  ["Plugins\OAuth2\Application\Ports\ClientStore"]
```
All control-plane tables (`oauth_clients`, `oauth_auth_codes`,
`oauth_refresh_tokens`, `oauth_scopes`, `oauth_device_codes`) are pinned to the
**central** connection.

> **Placement:** OAuth2 is a CENTRAL/control-plane concern — serve `/oauth/*` on
> the **apex/central host**, never tenant sub-domains. In host-tenancy mode set
> `TENANCY_BASE_DOMAINS` so the apex resolves to central.

---

## GRANTS

| Grant | Notes |
|---|---|
| `authorization_code` (+ **PKCE**) | exact-match `redirect_uri`; PKCE **mandatory for public clients** (S256/plain); codes random, hashed, 60s, single-use (atomic `consume`) |
| `client_credentials` | confidential clients only; no refresh token; `sub = client_id` |
| `refresh_token` | rotating + **family reuse-detection** (replay burns the family); scope narrowing only |
| `password` | confidential client; verifies via `ResourceOwnerVerifier` (User module); deprecated by OAuth 2.1 |
| `urn:…:device_code` | RFC 8628; `authorization_pending` / `slow_down` (interval-enforced) / `access_denied` / `expired_token`; single redemption |

Confidential clients ALWAYS authenticate (Basic or body secret, `hash_equals`);
public clients are identified by `client_id` + PKCE only.

---

## ENDPOINTS

| Method · Path | Purpose |
|---|---|
| `GET/POST /oauth/authorize` | Auth-code consent (session-auth gated; request stored **server-side**, form carries only an opaque `authz_id` — no PKCE/scope round-trip) |
| `POST /oauth/token` | token endpoint (all grants) |
| `POST /oauth/device_authorization` | device-code start (device_code + user_code) |
| `GET/POST /oauth/device` | device user-verification page |
| `GET /oauth/userinfo` | OIDC UserInfo (Bearer; requires `scope:openid`) |
| `POST /oauth/introspect` | RFC 7662 (client-authenticated) |
| `POST /oauth/revoke` | RFC 7009 — refresh family revoke **+ JWT `jti` deny-list** |
| `GET /oauth/jwks` | RFC 7517 JWKS (RSA + EC) |
| `GET /.well-known/oauth-authorization-server` · `/openid-configuration` | RFC 8414 / OIDC discovery |
| `GET /oauth/scopes` | scope catalogue **with descriptions** (`ScopeRegistry` over `ScopeStore::describe()`) — public |
| `GET/POST/PUT/DELETE /oauth/clients` · `/clients/{id}` | **self-service client mgmt** (`auth`-gated, owner-scoped via `owner_id`; secret shown ONCE on create; another owner's client → 404) |
| `GET/DELETE /oauth/authorized-tokens` · `/{id}` | **self-service authorized-apps** — list a user's active grants; delete revokes the whole rotation family (`RefreshTokenStore::findByUser`) |

The mgmt trio is the GDA-native port of Passport's `Client`/`AuthorizedAccessToken`/
`Scope` controllers. `ScopeRegistry` also exposes `scopesFor()`/`tokensCan()`/
`hasScope()` for consent screens. Personal (user) API keys are NOT here — those
are Auth PATs (`/auth/tokens`); `oauth_clients` stores APPLICATIONS, not user keys.

CSRF: the machine POSTs (`/oauth/token`, `/introspect`, `/revoke`,
`/device_authorization`) MUST be in `CsrfTokenLayer` `exemptPaths` (client-auth,
not cookie-auth); the browser consent forms (`/oauth/authorize`, `/oauth/device`)
stay CSRF-protected.

---

## TOKENS

- **Access token = JWT** signed with the platform key (`JWT_ALGO`/keys), so the
  existing `JwtAuthLayer` validates it. Claims: `iss`, `aud` (the **resource
  audience** `OAUTH_TOKEN_AUDIENCE` ∕ `JWT_AUDIENCE`, NOT the client), `azp`
  (client), `sub`, `scope`, `jti`, and `permissions` as **`scope:<name>`**
  (namespaced so an OAuth scope can NEVER satisfy a first-party
  `hasPermission('admin')`).
- **id_token** (OIDC) issued when `openid` is granted — carries `nonce`,
  `aud = client_id`, `auth_time`. Refused for a **public client under symmetric
  (HS) signing** (unverifiable) — OIDC needs RS/ES/PS keys.
- **Refresh token** = opaque, stored hashed, rotating.

---

## CLI

`oauth:client:create` (`--public` for PKCE clients; secret shown once),
`oauth:client:list`, `oauth:client:revoke`, `oauth:client:rotate`,
`oauth:prune [--watch=SECONDS]` (expired codes/refresh/device rows).

---

## CONFIG (env)

`OAUTH_ACCESS_TTL`, `OAUTH_REFRESH_TTL`, `OAUTH_CODE_TTL`, `OAUTH_DEVICE_TTL`,
`OAUTH_DEVICE_INTERVAL`, `OAUTH_TOKEN_AUDIENCE` (defaults to `JWT_AUDIENCE`).
Signing keys come from Auth's `JWT_*` (use **RS256 + key files** for OIDC).

---

## RULES

```
✓ Serve /oauth/* on the apex/central host (control-plane); set TENANCY_BASE_DOMAINS in host mode.
✓ Access tokens are platform JWTs — verified by JwtAuthLayer, no OAuth-specific resource-server code.
✓ Scopes ride in `scope` AND namespaced `scope:*` permissions — never bare RBAC names.
✓ redirect_uri EXACT match, validated before any error redirect; PKCE mandatory for public clients.
✓ Refresh rotation with family reuse-detection; auth codes single-use (burned on PKCE/redirect failure).
✓ OIDC (public clients) requires asymmetric signing (RS/ES/PS) + key files.
✗ Putting OAuth scopes into bare `permissions` (collision with first-party authz).
✗ CSRF-protecting the machine token/introspect/revoke endpoints (they are client-authenticated).
✗ A new vendor OAuth package — this server is native on firebase/php-jwt.
```
