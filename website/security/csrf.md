# CSRF Protection

The `CsrfTokenLayer` implements stateless CSRF protection using HMAC-signed tokens — the WordPress-nonce model. No server-side storage, no plain cookie-doubling, no vulnerability to cookie injection.

## How It Works

A CSRF token is a self-verifying HMAC:

```
token = tick . "." . hex( HMAC_SHA256( SECRET, tick | binding | action ) )
```

- **SECRET**: server-only key (`APP_KEY`) the attacker never possesses
- **tick**: coarse time window (half-lifetime intervals); tokens expire automatically
- **binding**: per-client value from an HttpOnly cookie; optional
- **action**: scope (e.g., "delete-post:42"), optional

Example token: `2042.6a7f8d2e4c9b1a5d3f8e0c2b4d6a8e1f`

## Why HMAC Tokens Over Double-Submit Cookies?

Plain double-submit checks **submitted value == cookie value**. An attacker who can WRITE a cookie (sibling subdomain, MITM on plain HTTP) plants a matching pair and passes the check.

With HMAC tokens:

- The token can't be produced without **SECRET**
- The token is NEVER read from or trusted in a cookie
- Binding to a cookie proves the client's identity
- Cookie injection buys the attacker nothing

**Result:** HMAC is stronger than double-submit and requires no database.

## Configuration

```php
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Layers\CsrfTokenLayer;

$kernel = Kernel::configure()
    ->withSecurity([
        new CsrfTokenLayer(
            secret: env('APP_KEY'),              // HMAC key (empty = fail-closed)
            headerName: 'X-CSRF-Token',          // request header name
            formField: '_csrf_token',            // fallback body/query field
            bindCookie: 'hkm_session',           // cookie to pin token to ('' = unbound)
            lifetime: 43200,                     // seconds (12 hours, default)
            exemptPaths: ['/api/webhooks'],      // paths that bypass the check
            exemptMethods: [],                   // extra safe methods
            clock: null,                         // inject for testing (e.g. frozen clock)
        ),
    ])
    ->build();
```

### Parameters in detail

| Parameter | Default | Purpose |
|---|---|---|
| `secret` | `env('APP_KEY')` | HMAC key; empty string = fail-closed (deny all) |
| `headerName` | `'X-CSRF-Token'` | request header carrying the token |
| `formField` | `'_csrf_token'` | fallback body/query field |
| `bindCookie` | `''` | HttpOnly session cookie name ('' = unbound, secret-only) |
| `lifetime` | `43200` | token validity in seconds (12 hours) |
| `exemptPaths` | `[]` | path prefixes that bypass the check |
| `exemptMethods` | `[]` | extra safe methods (GET/HEAD/OPTIONS already safe) |
| `clock` | `null` | inject for testability (frozen clock, no sleep) |

### Crucial: bindCookie must be raw and unencrypted

The layer reads the bound cookie from the raw `Cookie` request header at **SecurityStage** (before cookie decryption), so:

1. The bound cookie MUST be **HttpOnly** (no JavaScript access)
2. The bound cookie MUST NOT be **encrypted**
3. The bound cookie's `name` parameter must match `bindCookie`

If the session cookie is encrypted, you must add it to the `encrypt_exempt` list (a plugin feature).

```php
// Example: session cookie is HttpOnly and raw
Set-Cookie: hkm_session=abc123xyz; Path=/; HttpOnly; SameSite=Lax

// Layer reads from the Cookie header (raw, unencrypted)
// and verifies the token was signed with that binding value
```

## Issuing Tokens

### In forms

```php
// In a controller or view, mint a token
$token = $csrfLayer->issue($request);

// Or using the static API (if you don't have the layer instance)
$token = CsrfTokenLayer::make(env('APP_KEY'), $binding);
```

Render into a form:

```html
<form method="POST" action="/api/invoices">
    <input type="hidden" name="_csrf_token" value="<?php echo $token; ?>">
    <input type="text" name="title">
    <button type="submit">Create</button>
</form>
```

Or a meta tag for AJAX:

```html
<meta name="csrf-token" content="<?php echo $token; ?>">
```

### In AJAX / SPA

The client reads the token from the meta tag and sends it in the header:

```javascript
const token = document.querySelector('meta[name="csrf-token"]')?.content;

fetch('/api/invoices', {
    method: 'POST',
    headers: {
        'X-CSRF-Token': token,
        'Content-Type': 'application/json',
    },
    body: JSON.stringify({ title: 'Invoice' }),
});
```

## Verification

The layer automatically checks every unsafe request (POST, PUT, DELETE, PATCH):

```php
$verdict = $layer->check($request);

if ($verdict->isDenied()) {
    // Token missing or invalid
    return Response::forbidden($verdict->reason());
}
```

No GET/HEAD/OPTIONS requests require a token (safe methods).

### Manual verification (out-of-band)

If you want to verify a token yourself without relying on the layer:

```php
$isValid = CsrfTokenLayer::valid(
    secret: env('APP_KEY'),
    token: $submittedToken,
    binding: $sessionCookieValue,
    lifetime: 43200,
    clock: null,  // inject a frozen clock for testing
);

if (!$isValid) {
    throw new ValidationException(['_csrf_token' => 'Token invalid or expired']);
}
```

## Token Lifetime

Tokens expire after the configured `lifetime` (default 12 hours). A one-tick grace window is included (WordPress-nonce style):

- **tick = ceil(now / half_lifetime)** where half_lifetime = lifetime / 2
- A token issued at tick `T` is valid at ticks `T` and `T+1` (one interval grace)
- Older ticks are rejected

This means:

- A token lasts between 6–12 hours (lifetime ± half_lifetime)
- Browser clock skew of up to an hour is tolerated
- Tokens don't need server-side rotation — time handles it

## Exemptions

### Exempt paths (webhooks, third-party APIs)

```php
exemptPaths: [
    '/api/webhooks',
    '/webhooks/stripe',
],
```

These paths bypass CSRF checking. Use only for:

- Webhook endpoints where the caller is a third-party service
- Machine-to-machine APIs that can't send CSRF tokens

::: warning Matching is a raw string prefix
Each entry is compared with `str_starts_with($path, $prefix)`, with no segment boundary. `'/api'` exempts `/api/orders`, and also `/apiary` and `/api-admin`. End a prefix with `/` (`'/api/'`) when you mean a directory, and keep the list as narrow as possible: every exempt path is a path an attacker's form can post to.
:::

### Exempt methods

Extra safe methods (GET/HEAD/OPTIONS are always safe):

```php
exemptMethods: ['TRACE'],
```

## Testing

### Injecting a frozen clock

```php
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\ClockPort;

final class FrozenClock implements ClockPort
{
    public function __construct(private int $timestamp) {}

    public function now(): \DateTimeImmutable
    {
        return (new \DateTimeImmutable())->setTimestamp($this->timestamp);
    }

    public function timestamp(): int { return $this->timestamp; }

    public function advance(int $seconds): void { $this->timestamp += $seconds; }
}

$layer = new CsrfTokenLayer(
    clock: new FrozenClock(1640995200),  // frozen at a specific time
);

// Tokens can be tested for expiry without sleeping
$token = $layer->issue($request);
// ... advance time by modifying the clock
// ... assert the token is valid/expired
```

### Testing tokens

```php
public function test_csrf_token_expires_after_lifetime(): void
{
    $clock = new FrozenClock(1640995200);
    $layer = new CsrfTokenLayer(
        secret: 'secret-key',
        lifetime: 3600,  // 1 hour
        clock: $clock,
    );
    
    $token = CsrfTokenLayer::make('secret-key', 'binding', 3600, '', $clock);
    
    // Token valid at issue time
    $this->assertTrue(CsrfTokenLayer::valid('secret-key', $token, 'binding', 3600, $clock));
    
    // Token valid one tick later (within grace)
    $futureTime = new FrozenClock(1640995200 + 1800 + 1);  // past grace window
    $this->assertFalse(CsrfTokenLayer::valid('secret-key', $token, 'binding', 3600, $futureTime));
}
```

## Common Patterns

### SPA with token refresh

```javascript
// On page load, fetch a fresh token
async function refreshCsrfToken() {
    const response = await fetch('/api/csrf-token');
    const { token } = await response.json();
    
    document.querySelector('meta[name="csrf-token"]').content = token;
    
    return token;
}

// Refresh before sending
const token = await refreshCsrfToken();

fetch('/api/invoices', {
    method: 'POST',
    headers: { 'X-CSRF-Token': token },
    body: JSON.stringify({ ... }),
});
```

### Action-scoped tokens (for link-based forms)

A token can scope to a specific action (e.g., "delete-post:42"):

```php
// In a view rendering a delete form
$deleteToken = CsrfTokenLayer::make(
    env('APP_KEY'),
    $sessionCookie,
    43200,
    'delete-invoice:' . $invoiceId,
);

// Pass to the template
<form method="POST" action="/api/invoices/{{ $id }}/delete">
    <input type="hidden" name="_csrf_token" value="{{ $deleteToken }}">
    <button type="submit">Delete</button>
</form>

// In the controller, verify the scoped token
// (the action is already encoded in the token; just verify the token)
$token = $request->input('_csrf_token');
if (!CsrfTokenLayer::valid(env('APP_KEY'), $token, $binding, 43200)) {
    throw new ValidationException(['_csrf_token' => 'Invalid token for this action']);
}
```

## Configuration in Bootstrap

```php
<?php
declare(strict_types=1);

return Kernel::configure()
    ->withSecurity([
        new CsrfTokenLayer(
            secret: env('APP_KEY'),
            headerName: config('security.csrf.header_name', 'X-CSRF-Token'),
            formField: config('security.csrf.form_field', '_csrf_token'),
            bindCookie: config('security.csrf.bind_cookie', 'hkm_session'),
            lifetime: config('security.csrf.lifetime', 43200),
            exemptPaths: config('security.csrf.exempt_paths', []),
            exemptMethods: config('security.csrf.exempt_methods', []),
        ),
    ])
    ->build();
```

And in `config/security.php`:

```php
<?php
return [
    'csrf' => [
        'header_name' => env('CSRF_HEADER_NAME', 'X-CSRF-Token'),
        'form_field' => env('CSRF_FORM_FIELD', '_csrf_token'),
        'bind_cookie' => env('CSRF_BIND_COOKIE', 'hkm_session'),
        'lifetime' => env('CSRF_LIFETIME', 43200),
        'exempt_paths' => [
            '/api/webhooks/stripe',
            '/webhooks',
        ],
    ],
];
```

## Common Mistakes

::: danger Never use a rotating session cookie for binding

```php
// ✗ WRONG — if the session cookie rotates (e.g., on login),
// the binding value changes and previously-issued tokens become invalid
bindCookie: 'session_id',  // (if this cookie rotates)

// ✓ Correct — use a stable HttpOnly cookie
bindCookie: 'hkm_session',  // stable, not rotated on login/logout
```

This is why a dedicated, stable session identifier is needed.

:::

::: danger Don't decrypt the binding cookie

```php
// ✗ WRONG — the layer reads the raw Cookie header
// but plugin encryption happens AFTER SecurityStage
// so an encrypted cookie is unreadable by the layer
bindCookie: 'session_id',  // (if encrypted)

// The layer reads:
// Cookie: session_id=Z0FBQUFBQm9ub3JT... (encrypted)
// and tries to verify the token against that ciphertext
// which won't match the plaintext the browser sent last time
```

Add the binding cookie to the `encrypt_exempt` list if your session cookie is encrypted.

:::

::: danger Don't omit the secret

```php
// ✗ WRONG — empty secret = fail-closed (deny all)
new CsrfTokenLayer(secret: '');

// Every POST/PUT/DELETE request is denied with:
// "CSRF secret not configured"

// ✓ Ensure APP_KEY is set
env('APP_KEY');  // .env or .env.local
```

:::

::: info Tokens don't need storage

Unlike traditional CSRF tokens, there's no `csrf_tokens` table. The token IS the proof (HMAC signature). Storage was never needed.

:::

## Source

- [CsrfTokenLayer.php](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Security/Layers/CsrfTokenLayer.php)
- [docs/ai-context/21_CSRF.md](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/docs/ai-context/21_CSRF.md) (comprehensive CSRF design document)
