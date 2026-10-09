# Named Routes and URL Generation

Route names decouple URLs from your code. When a project overrides or moves a route, links built with `route()` automatically point to the new location. Without names, a plugin's view linking to `/register` breaks the moment a project customizes that page.

## Naming Routes

Add a `name` to any route:

```jsonc
{
  "routes": [
    { "method": "GET", "path": "/posts", "handler": "Shop\\Http\\PostController@index", "name": "posts.index" },
    { "method": "GET", "path": "/posts/{id}", "handler": "Shop\\Http\\PostController@show", "name": "posts.show" },
    { "method": "POST", "path": "/posts", "handler": "Shop\\Http\\PostController@create", "name": "posts.store" }
  ]
}
```

Names must be **unique application-wide** — two routes cannot claim the same name. A collision fails at boot.

### Naming with Groups

Use a group's `name` to prefix routes, avoiding repetition:

```jsonc
{
  "groups": [
    {
      "prefix": "/api",
      "name": "api.",
      "routes": [
        { "name": "posts.index", ... },
        { "name": "posts.show", ... }
      ]
    }
  ]
}
```

Final names:
- `api.posts.index`
- `api.posts.show`

An unnamed route stays unnamed — a group's `name` prefix does not invent names.

## Building URLs

The `route()` helper generates URLs for named routes:

```php
route('posts.index');                // /posts
route('posts.show', ['id' => 7]);    // /posts/7
route('posts.show', ['id' => 7], true);  // https://app.example.com/posts/7 (absolute)
```

### URL Parameters

Parameters not consumed by path placeholders become query string:

```php
route('posts.index', ['page' => 2, 'sort' => 'date']);  // /posts?page=2&sort=date
```

Optional parameters (declared with `?`) drop their leading `/` when absent. For a route `GET /archive/{page?}` named `posts.archive`:

```php
route('posts.archive');                    // /archive        (null or '' counts as omitted)
route('posts.archive', ['page' => '2']);   // /archive/2
```

### Type Validation at Generation Time

The generator validates that provided values satisfy their parameter types:

```php
route('posts.show', ['id' => 7]);       // ✓ valid for {id:num}
route('posts.show', ['id' => 'abc']);   // ✗ throws InvalidArgumentException
route('file', ['name' => '../etc']);    // ✗ throws InvalidArgumentException for {name:path}
```

A type violation is caught at the call site, before being rendered in a response. This turns a silent 404 into an exception during development.

## The UrlGenerator Class

Build URLs programmatically:

```php
use AlfacodeTeam\PhpServicePlatform\Kernel\Routing\UrlGenerator;

$gen = UrlGenerator::fromManifest(
    base: 'https://app.example.com',
    secret: env('APP_KEY'),  // for signed URLs
);

$gen->has('posts.show');                    // true
$gen->methodFor('posts.store');             // 'POST'
$gen->route('posts.show', ['id' => 7]);     // /posts/7
$gen->to('/contact');                       // /contact (literal path escape hatch)
```

Methods:

| Method | Returns | Description |
|--------|---------|-------------|
| `has(name)` | bool | Whether a route is named |
| `methodFor(name)` | ?string | HTTP method a route answers (e.g., 'POST') |
| `route(name, params, absolute)` | string | URL for a named route |
| `signedRoute(name, params, expiresIn, absolute)` | string | Tamper-proof URL (see [Signed URLs](#signed-urls)) |
| `hasValidSignature(url)` | bool | Verify a signed URL |
| `to(path, query, absolute)` | string | Literal path URL (escape hatch) |
| `domainFor(name)` | string | Domain group a route belongs to ('' for ungrouped) |

## Signed URLs

A signed URL includes an HMAC that proves the URL has not been tampered with. Use them for email verification links, one-time action URLs, and password resets.

```php
$url = signed_route('password.reset', ['token' => $token], expiresIn: 3600);
// /password/reset?token=abc&expires=1234567890&signature=…

// Later, verify it:
if (url()->hasValidSignature($request->path() . '?' . $request->server('QUERY_STRING'))) {
    // safe to process
}
```

### How Signing Works

The generator appends an HMAC over the path and query string (but not the host, so multi-brand apps can choose their base per domain):

```php
signedRoute('email.verify', ['code' => '123'], expiresIn: 86400);
// /verify/email?code=123&expires=1700000000&signature=abc123def456…
```

The signature covers the path and query exactly as sent. If a recipient edits `code=123` to `code=456`, the HMAC no longer matches and `hasValidSignature()` returns false.

An optional `expires` timestamp is included in the signature, so an attacker cannot extend the deadline by editing the URL.

### Signing Secret

By default, signing uses the `APP_KEY` environment variable. Fail closed if no key is configured:

```php
signed_route('verify', ['code' => 'x']);  // throws RuntimeException if APP_KEY is empty
```

Provide a custom secret:

```php
$gen = UrlGenerator::fromManifest(secret: $customSecret);
$url = $gen->signedRoute('verify', ['code' => 'x']);
```

The secret must be the same when signing and verifying. Changing `APP_KEY` invalidates all signed URLs in the wild — plan accordingly.

## Absolute URLs

Pass `absolute: true` to get a full URL including scheme and host:

```php
route('posts.show', ['id' => 7], absolute: true);
// https://app.example.com/posts/7
```

The base URL comes from `APP_URL` environment variable (or defaults to `https://` if absent).

For routes grouped under a concrete domain, the generator uses that domain instead:

```jsonc
{
  "groups": [
    { "domain": "shop.example.com", "routes": [{ "name": "home", ... }] }
  ]
}
```

```php
route('home', absolute: true);
// https://shop.example.com/ (not the APP_URL host)
```

A wildcard or bare subdomain has no single host, so it falls back to `APP_URL`.

## Escape Hatches

### Literal Paths

When a route has no name (an external link, a plugin route you did not author), use `url()->to()`:

```php
url()->to('/external-page');
url()->to('/search', ['q' => 'laravel']);
```

### Route Inspection

Rarely, you need to inspect a route without generating its URL:

```php
if (url()->has('admin.users.edit')) {
    // admin.users.edit is available
}

$method = url()->methodFor('api.posts.create');  // 'POST'
```

## Performance

UrlGenerator reads from a lightweight `route-names.php` index (compiled at boot) that contains only name → `{path, method, domain}` mappings. A CLI command that mints one email verification link does not load your entire routing surface into memory.

For bare lookups (a test, a startup script), it reads the full `route-manifest.php` if the names index is absent (e.g., a deploy that predates it).

## Common Mistakes

::: danger
**Do not hardcode paths in links:**

```php
✗ <a href="/posts/{{ post.id }}">View</a>
✓ <a href="{{ route('posts.show', ['id' => post.id]) }}">View</a>
```

Hardcoded paths break when a project moves or renames a route. Named routes survive overrides.
:::

::: danger
**Do not build URLs conditionally; declare them:**

```php
✗ if (feature_enabled('admin')) {
    $url = '/admin/users';
  } else {
    $url = '/users';
  }

✓ // both declared as named routes
  route(feature_enabled('admin') ? 'admin.users' : 'users')
```

A missing route is a boot failure, not a silent null.
:::

::: warning
**Signed URLs expire:**

```php
$url = signed_route('reset', ['token' => $t], expiresIn: 86400);  // 24 hours
// if sent in an email and opened after 24h, hasValidSignature() returns false
```

Plan your expiry times accordingly. Email links often need days, not hours.
:::

## Next Steps

- [Route Basics](/routing/basics) — route declaration reference
- [Route Parameters](/routing/parameters) — type validation on URL generation
- [Route Cookbook](/routing/cookbook) — URL generation examples

## Source

- [UrlGenerator.php](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Routing/UrlGenerator.php) — URL generation and signing
- [helpers.php](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Support/helpers.php) — `route()` and `signed_route()` helpers
- [RouteIndex.php](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Routing/RouteIndex.php) — route name indexing
