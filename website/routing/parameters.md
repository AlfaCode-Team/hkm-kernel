# Route Parameters

Placeholders in route paths capture segments of the incoming request and pass them to your handler. Parameters can be typed to enforce constraints at the routing layer instead of in controllers.

## Basic Syntax

An untyped parameter matches one path segment:

```json
{ "path": "/users/{id}" }
```

Matches: `/users/7`, `/users/alice`, `/users/any-string`

Does not match: `/users/7/posts` (two segments), `/users/` (empty)

## Typed Parameters

Add a colon and type name to constrain what the parameter accepts:

```json
{ "path": "/users/{id:num}" }
```

Matches: `/users/7`, `/users/123`

Does not match: `/users/alice` (not numeric), `/users/` (empty)

### Parameter Types

| Type | Regex | Matches | Use case |
|------|-------|---------|----------|
| *(untyped)* | `[^/]+` | one segment (default behavior) | any single path element |
| `num` | `[0-9]+` | numeric ID | `/posts/{id:num}` |
| `alpha` | `[a-zA-Z]+` | alphabetic | `/categories/{name:alpha}` |
| `alphanum` | `[a-zA-Z0-9]+` | alphanumeric | `/products/{code:alphanum}` |
| `slug` | `[a-zA-Z0-9_-]+` | URL-friendly | `/articles/{slug:slug}` |
| `uuid` | UUID v4 format | UUIDs | `/events/{id:uuid}` |
| `segment` | `[^/]+` | one segment | same as untyped; kept for compatibility |
| `any` | `.*` | any characters (crosses `/`) | **risky** — no traversal guard |
| `path` | traversal-safe catch-all | any string except `..` and control chars | `/files/{name:path}` |
| `enum(a\|b\|c)` | closed set | one of the listed values | `/status/{state:enum(draft\|published\|archived)}` |

### `path` vs `any`

Both cross path segment boundaries (`/`), but differ in safety:

- **`any`** — matches literally anything, including `..` and control characters. A request for `/files/..%2F..%2Fetc%2Fpasswd` decodes to `../../etc/passwd` and still matches. **Avoid for anything reaching the filesystem or a `StoragePort`.**

- **`path`** — refuses `..` anywhere in the segment and control characters (`\x00`–`\x1f`, `\x7f`). A request for `/files/..%2F..%2Fetc%2Fpasswd` decodes to `../../etc/passwd`, fails the pattern, and moves on (or 404s if no other route matches). **Prefer this for file serving.**

## Optional Parameters

Append `?` to make a parameter optional. The separator (usually `/`) is folded into the optional group:

```json
{ "path": "/posts/{page?}" }
```

Matches: `/posts` (page = `''`), `/posts/2` (page = `'2'`)

Does not match: `/posts/` (the alternate form is tried if `ROUTE_TRAILING_SLASH=ignore`)

An omitted optional parameter is reported to the controller as an empty string, not null.

## Percent-Decoding and Re-Validation

The matcher captures the **raw** (percent-encoded) path. Each captured value is then decoded and **re-validated** against its type:

```
Raw:     /users/%32%30  (the string "20" percent-encoded)
Decoded: /users/20       (valid for {id:num})
Param:   id = '20'

Raw:     /files/..%2F..%2Fetc%2Fpasswd
Decoded: /files/../../etc/passwd (invalid for {name:path} — contains ..)
Result:  no match (404, or try the next route)
```

This guarantees that a controller receives a value that satisfies its type constraint, even if the wire bytes were percent-encoded.

::: tip
Because of this re-validation, `%2F` (encoded `/`) cannot smuggle a slash past a `{name}` parameter. The decoded value is checked against the pattern, so traversal escapes and null-byte injection are caught at the routing layer.
:::

## Capture-Name Sanitization

Placeholder names are sanitized to become valid PCRE named capture groups. Non-word characters are stripped:

```
Declared: {user-id}        becomes userid
Declared: {2fa}            becomes fa (invalid — starts with 'f' but empty after stripping)
Declared: {_private}       becomes _private (valid)
Declared: {page?}          becomes page
```

A name that produces an invalid capture group (empty, starts with a digit) fails at boot with a clear message. The kernel suggests renaming: `{2fa}` → `{twoFactor}`.

The stripped name is what the controller receives as a route parameter:

```php
public function show(string $id)  // received from {user-id}, sanitized to userid
{ ... }
```

## Enum Parameters

A closed set of literal values:

```json
{ "path": "/articles/{status:enum(draft|published|archived)}" }
```

Matches: `/articles/draft`, `/articles/published`

Does not match: `/articles/pending` (not in the set)

Members are restricted to `[A-Za-z0-9_.-]` — no regex metacharacters can be injected from JSON, preventing ReDoS (regular expression denial of service) attacks.

## URL Generation with Typed Parameters

When building URLs with `route()`, the kernel validates that provided values satisfy their type:

```php
route('post.show', ['id' => 7]);          // ✓ valid for {id:num}
route('post.show', ['id' => 'abc']);      // ✗ throws InvalidArgumentException
route('status', ['state' => 'draft']);    // ✓ valid for {state:enum(...)}
route('file', ['name' => '../etc']);      // ✗ throws InvalidArgumentException for {name:path}
```

Parameters not consumed by a path placeholder become the query string:

```php
route('search', ['q' => 'laravel', 'page' => '2']);
// /search?q=laravel&page=2
```

::: info
Type validation at generation time turns a broken link into an exception at the call site, instead of a silent 404 in production.
:::

## Several Parameters, Unique Names

A path may declare as many placeholders as it needs, but each **name** must be unique:

```json
{ "path": "/from/{from:num}/to/{to:num}" }
```

`/users/{id}/posts/{id}` fails the boot: a duplicate name would compile to a regex PCRE rejects, so `RouteParameter::compile()` throws with a message naming the path.

Values reach the action **in the order the placeholders appear in the path**, not by name. See [Controllers](/http/controllers#route-parameter-passing).

## Boot-Time Validation

The compiler rejects parameter declarations that cannot work:

- **Unknown type** — `{id:badtype}` is not in the type table. Run `RouteParameter::names()` to see valid types.
- **Duplicate capture name** — `/{id}/{id}` would compile to a regex with two groups of the same name, which is invalid in PCRE.
- **Invalid capture name** — `{2fa}` produces an unusable group name; the kernel suggests `{twoFactor}`.
- **Malformed enum** — `enum(a|)` (empty member) or `enum(a/b)` (member with unescaped regex char) fails the boot.

## Differences from Previous Versions

In HKM 0.3, placeholders used a different syntax:

```
0.3:       /users/(:num)
Sentinel:  /users/{id:num}

0.3:       /files/(:any)
Sentinel:  /files/{name:any}  (or safer: /files/{name:path})
```

Untyped parameters retain their exact 0.3 meaning (`[^/]+`), so existing routes translate without changes.

## Next Steps

- [Route Basics](/routing/basics) — declaring routes
- [URL Generation](/routing/named-routes) — building URLs with parameters
- [Route Cookbook](/routing/cookbook) — worked examples

## Source

- [RouteParameter.php](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Routing/RouteParameter.php) — parameter type definitions and validation
- [RouteMatcher.php](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Pipelines/Http/RouteMatcher.php) — matching algorithm with decoding and re-validation
