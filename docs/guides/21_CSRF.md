# HKM Kernel — CSRF Protection (CsrfTokenLayer)

> `CsrfTokenLayer` is a **kernel** security layer. It runs inside the
> SecurityGateway **before any module loads**, so a forged request is denied at
> microsecond cost without touching domain code.
>
> Location: `src/Kernel/Security/Layers/CsrfTokenLayer.php`
> Contract: `SecurityLayerContract` (see [09_SECURITY.md](09_SECURITY.md))

---

## What it is — HMAC token, NOT plain double-submit

`CsrfTokenLayer` uses the **WordPress-nonce model**: a stateless, HMAC-signed
token. Nothing is stored server-side, and — crucially — **no cookie value is
ever trusted as the token**.

```
token = tick . "." . hex( HMAC_SHA256( SECRET, tick . "|" . binding . "|" . action ) ) [ . action ]
```

| Part | Meaning |
|---|---|
| `SECRET`  | A server-only key (`APP_KEY`). The attacker never has it. |
| `tick`    | A coarse time window → the token expires (default lifetime 12h, with a 1-tick grace, exactly like WordPress). |
| `binding` | An opaque per-client value read from a cookie the attacker **cannot read** (e.g. the HttpOnly session cookie). Optional. |
| `action`  | Optional scope, e.g. `"delete-post:42"`; `''` for a global token. Signed, so it cannot be tampered. |

### Why this is stronger than double-submit

Plain double-submit only checks *"submitted value == cookie value"*. An attacker
who can **write** a cookie — a compromised sibling sub-domain (`evil.example.com`
setting a cookie on `.example.com`), or a MITM on plaintext HTTP — can plant a
matching cookie/token pair and pass the check. Here there is **no cookie to
trust**: a valid token cannot be produced without `SECRET`, so cookie injection
buys the attacker nothing.

| | Plain double-submit | CsrfTokenLayer (HMAC) |
|---|---|---|
| Server stores a token? | No | No |
| Token delivered in a cookie? | **Yes (trusted)** | No (header/form only) |
| Bound to the client? | No | Yes (via `bindCookie`) |
| Survives cookie injection? | **No** ❌ | Yes ✅ |
| Needs a server secret? | No | Yes (`APP_KEY`) |
| Expires automatically? | No | Yes (`tick`) |

---

## The lifetime is in SECONDS

`$lifetime` is **seconds** (default `43200` = 12h). The tick is a half-life
window with a one-tick grace, so a token is valid for **6h–12h** with the
default — overlapping windows, identical to WP nonces.

```php
tick = ceil( time() / max(1, intdiv($lifetime, 2)) );   // time() is Unix seconds
// verify accepts the current tick OR the immediately previous one (grace)
```

---

## Constructor — framework-level wiring

Wire it in `withSecurity([...])`. `CsrfTokenLayer` is the one CSRF layer the kernel ships;
add the Auth plugin's token layers alongside it (rate limiting / IP shield are separate
SecurityFilters route filters, not gateway layers):

```php
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Layers\CsrfTokenLayer;

->withSecurity([
    new CsrfTokenLayer(                                                               // kernel — stateless HMAC
        // secret:   null,            // defaults to env('APP_KEY'); fail-closed if empty
        headerName:  'X-CSRF-Token',  // request header carrying the echoed token
        formField:   '_csrf_token',   // fallback body/query field
        bindCookie:  'hkm_session',   // pin the token to this (HttpOnly) cookie's value; '' = unbound
        lifetime:    43200,           // SECONDS (12h)
        exemptPaths: ['/api'],        // path prefixes that bypass (machine-to-machine, own auth)
        exemptMethods: [],            // extra safe methods (GET/HEAD/OPTIONS are always safe)
    ),
    // Token/JWT auth is a separate concern → provide it via your AuthModule.
])
```

| Param | Default | Notes |
|---|---|---|
| `secret` | `env('APP_KEY')` | HMAC key. **Empty ⇒ fail-closed** (every unsafe request denied — never silently open). |
| `headerName` | `X-CSRF-Token` | Where JS/fetch sends the token. |
| `formField` | `_csrf_token` | Where an HTML `<form>` sends it. |
| `bindCookie` | `''` | Cookie whose **raw** value pins the token to one client. Use the HttpOnly session cookie. `''` = secret-only (still unforgeable, just not per-client). |
| `lifetime` | `43200` | Seconds. |
| `exemptPaths` | `[]` | Prefix match. APIs that authenticate per-request belong here. |
| `exemptMethods` | `[]` | Case-insensitive; on top of the always-safe GET/HEAD/OPTIONS. |

**Prerequisite:** `APP_KEY` must be set in `.env`. The Session plugin already
uses it, so a project with sessions has it. Read it with the `env()` helper —
never `getenv()`.

---

## How verification flows through the gateway

```
unsafe request (POST/PUT/PATCH/DELETE)
      │
      ▼
CsrfTokenLayer::check(Request)         ← SecurityGateway, layer 3
  1. GET/HEAD/OPTIONS or exemptMethods?  → allow
  2. path under an exemptPath?           → allow
  3. APP_KEY empty?                      → DENY 403 (fail-closed)
  4. token from header ?? formField      → missing → DENY 403
  5. read bindCookie value from raw Cookie header
  6. recompute HMAC for current & previous tick, hash_equals()
       mismatch / expired                → DENY 403
       match                             → allow → controller runs
```

A denied token returns **403 before the controller is ever constructed** — the
controller only runs on a valid token, so controllers never re-check CSRF.

---

## Minting & verifying tokens — the static API

A controller/view does **not** hold the layer instance. Use the public statics
(same algorithm the layer verifies with):

```php
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Layers\CsrfTokenLayer;

// Mint a token to embed in a <meta> tag / hidden form field:
$token = CsrfTokenLayer::make(
    secret:   (string) env('APP_KEY'),
    binding:  $bindingValue,   // the SAME value that will be in bindCookie on the next request
    lifetime: 43200,
);

// Out-of-band verification (e.g. a custom AJAX check that must NOT deny):
$ok = CsrfTokenLayer::valid((string) env('APP_KEY'), $token, $bindingValue, 43200);
```

There is also an instance method when you *do* have the layer:
`$layer->issue($request, $action = '')` — mints for the current request's binding.

> ⚠ The `lifetime` passed to `make()`/`valid()` MUST equal the layer's configured
> `lifetime`, or the ticks won't line up and valid tokens read as expired.

---

## The binding gotcha (read this before binding to a cookie)

The layer reads `bindCookie`'s value **verbatim from the raw `Cookie` header**.
Two consequences:

1. **Mint with the value the client will send back.** On a first visit the cookie
   may not exist yet (it's set in the *response*). Generate the binding, set it,
   and mint with the value you are setting — so it matches on the next request.

2. **The bound cookie must NOT be encrypted by `CookieJar`.** `CookieJar` encrypts
   queued cookies by default, AND it re-encrypts with a fresh random IV on every
   flush — so the ciphertext in the header changes between requests. The layer
   runs at `SecurityStage` (before `after.load`), reads the raw header, and would
   see that rotating ciphertext → intermittent `403 CSRF token invalid`. Avoid it
   one of three ways:
   - **Add the binding cookie to `encrypt_exempt`** (`COOKIE_ENCRYPT_EXEMPT` env or
     the base list in `plugins/Cookie/config/cookie.php`). It is then stored AND
     read as plaintext, so its raw value is byte-stable — the cleanest option for
     pinning to the session cookie. See [First-party plugins → Cookie](20_FIRST_PARTY_PLUGINS.md).
   - Queue a dedicated binding cookie with `raw: true` and read it back with
     `$request->cookie(...)` (NOT `$this->cookie(...)`, which tries to decrypt).
   - Bind to a cookie that is not re-written every response (so its value never
     rotates), or use `bindCookie: ''` for a secret-only (unbound) token.

---

## End-to-end controller example (project layer)

```php
final class CsrfController extends ViewController   // has ViewRendererContract injected
{
    private const LIFETIME = 43200;     // MUST match the layer config
    private ?string $bind = null;

    /** GET — render the form with a freshly minted token. */
    public function form(): Response
    {
        return $this->view('csrf/form', [
            'title'     => 'CSRF demo',
            'csrfToken' => CsrfTokenLayer::make((string) env('APP_KEY'), $this->binding(), self::LIFETIME),
        ], layout: 'layouts/app');           // layout puts it in <meta name="csrf-token">
    }

    /** POST — reaching here PROVES the token was valid (gateway denied otherwise). */
    public function submit(): Response
    {
        return $this->view('csrf/result', ['message' => $this->request->input('message', '')], layout: 'layouts/app');
    }

    /** The per-client binding, read raw and (re)issued unencrypted. */
    private function binding(): string
    {
        if ($this->bind !== null) return $this->bind;
        $bind = $this->request->cookie('csrf_bind');                 // raw — NOT $this->cookie()
        if ($bind === null || $bind === '') {
            $bind = bin2hex(random_bytes(16));
            $this->cookieJar()?->queue('csrf_bind', $bind, raw: true); // raw:true → unencrypted
        }
        return $this->bind = $bind;
    }
}
```

Layout (`<meta>` for JS) — `layouts/app.php`:
```php
<?php if (!empty($csrfToken)): ?>
<meta name="csrf-token" content="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
<?php endif; ?>
```

Form (hidden field) + JS fetch reading the meta tag:
```html
<form method="POST" action="/csrf">
    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
    <button>Submit</button>
</form>

<script>
const token = document.querySelector('meta[name="csrf-token"]').content;
await fetch('/csrf', { method: 'POST', headers: { 'X-CSRF-Token': token } });
</script>
```

---

## AI / contributor rules for CSRF code

```
✓ CsrfTokenLayer is a KERNEL layer — wire it in withSecurity(), keep it 3rd (after firewall + rate limiter).
✓ SECRET defaults to env('APP_KEY'); an empty key fail-closes (denies) — never make it silently allow.
✓ lifetime is in SECONDS; make()/valid() lifetime MUST equal the layer's.
✓ Mint with CsrfTokenLayer::make(); verify out-of-band with CsrfTokenLayer::valid(); both use hash_equals internally.
✓ Bind to an HttpOnly cookie's RAW value (session cookie, or a raw:true binding cookie).
✓ Deliver the token in a <meta> tag (JS) and/or a hidden _csrf_token field (HTML) — NEVER as the trusted token cookie.
✗ Do NOT re-implement plain double-submit (trusting cookie == submitted value) — it's bypassable by cookie injection.
✗ Do NOT read the bound cookie via CookieJar/$this->cookie() (it decrypts) — read raw via $request->cookie().
✗ Do NOT encrypt the binding cookie (queue it raw:true) — the layer reads the raw header value.
✗ Do NOT re-check CSRF in the controller — the gateway already denied invalid tokens upstream.
✗ Do NOT exempt a state-changing browser route; only exempt machine-to-machine paths with their own auth (e.g. /api).
```
