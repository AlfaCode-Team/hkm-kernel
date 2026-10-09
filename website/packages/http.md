# http — HTTP Primitives Package

The `alfacode-team/http` package provides the immutable HTTP Request, Response, and UploadedFile value objects that the kernel uses. It lives in `modules/http/` as a Git submodule and is autoloaded as a Composer path repository.

## Location and namespace

| Aspect | Details |
|---|---|
| **Composer package** | `alfacode-team/http` |
| **Filesystem** | `modules/http/` (Git submodule) |
| **Namespace** | `AlfacodeTeam\PhpServicePlatform\Kernel\Http` (unchanged from kernel) |
| **Entry point** | `modules/http/src/` |

The namespace is kept as `Kernel\Http` rather than a separate namespace to make the extraction transparent: consuming code refers to `Request` and `Response` as if they were part of the kernel itself.

## Engine and transitional status

The package is currently built **on top of Symfony HTTPFoundation** (Request extends `Symfony\HttpFoundation\Request`, Response extends `Symfony\HttpFoundation\Response`). This is a deliberate, temporary choice:

- **Why Symfony**: battle-tested parser and emitter, handles edge cases in HTTP headers, multipart bodies, and streaming that would be complex to reimplement
- **Why transitional**: the kernel aims to become **dependency-free**. When this happens, Request / Response / UploadedFile will be reimplemented as pure value objects with zero vendor coupling

**Treat Symfony as an implementation detail.** Consuming code must depend only on the kernel's own API surface (`$request->method()`, `$response->json()`, etc.), never on Symfony classes directly. This makes the eventual switch to dependency-free implementations a non-breaking change.

## Submodule initialization

If `modules/http/` is missing after cloning or pulling, initialize the submodule:

```bash
git submodule update --init modules/http
composer install
```

Then regenerate the autoloader:

```bash
composer dump-autoload
```

## What's inside

The package exports:

| Class | Role | Immutability |
|---|---|---|
| `Request` | Inbound HTTP request; extends `Symfony\HttpFoundation\Request` | Immutable via cloning |
| `Response` | Outbound HTTP response; extends `Symfony\HttpFoundation\Response` | Immutable via named constructors |
| `UploadedFile` | A file uploaded via multipart form; extends `Symfony\HttpFoundation\File\UploadedFile` | Immutable (move/rename don't mutate) |
| `Uri` | Immutable PSR-7 URI value object | Read-only |
| `SiteUri` | Absolute URL builder (host-aware) | Read-only |
| `Negotiate` | Content negotiation (Accept-* headers) | Stateless |
| `UserAgent` | Parsed User-Agent string | Immutable |
| `Method` | HTTP method enum (`GET`, `POST`, etc.) | Enum |

## Request immutability

`Request` follows the immutable pattern: every mutator returns a NEW instance. The original is never changed:

```php
$original = $request->withAttribute('locale', 'fr');
// $request is UNCHANGED; $original is a new instance

$cloned = $request->withHeader('X-Trace-ID', $id);
// $request is UNCHANGED; $cloned is a new instance
```

This is load-bearing for Swoole/coroutine safety. A request cloned in one coroutine will not have mutations applied by another coroutine reaching it.

The `__clone()` method deep-clones the internal parameter bags (`headers`, `query`, `post`, etc.) so clones are fully isolated.

## Response immutability

`Response` is built via fluent, immutable named constructors:

```php
Response::json($data, 201)
    ->withHeader('Cache-Control', 'no-store')
    ->withCookie('session', $token, maxAge: 3600);
    // Each call returns a new Response; the previous one is unchanged
```

## API documentation

The complete API for Request and Response is documented separately:
- [/http/request](/http/request) — detailed Request API and usage
- [/http/response](/http/response) — detailed Response API and usage

This page covers the package structure and transitional nature only.

## Requirements

| Requirement | Version |
|---|---|
| PHP | 8.4+ |
| Symfony HTTPFoundation | 6.0+ or 7.0+ |
| Symfony MIME | 6.0+ or 7.0+ |

## Usage notes for developers

### Do NOT depend on Symfony directly

Code in modules and projects should import from the kernel's namespace:

```php
// ✓ Correct
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;

// ✗ Wrong — ties you to the implementation detail
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
```

If you need Symfony-specific features not exposed by the kernel's API, that is a sign the kernel's API is incomplete — file an issue rather than bypassing it.

### URL helpers work around Symfony

The kernel ships its own helpers for URL building that do NOT depend on Symfony:

```php
$request->uri()         // PSR-7 URI interface (safe for override)
$request->site()->to()  // Absolute URL from DomainContext (host-validated)
route('name', [...])    // Named routes (survives project changes)
```

Use these instead of Symfony's `Request::getUri()` or `Request::getSchemeAndHttpHost()`.

## Source

- [modules/http/](https://github.com/AlfaCode-Team/hkm-kernel/tree/main/modules/http)
- [modules/http/src/](https://github.com/AlfaCode-Team/hkm-kernel/tree/main/modules/http/src)
