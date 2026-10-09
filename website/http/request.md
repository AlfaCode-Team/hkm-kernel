# Request

The `Request` class provides an immutable, strongly-typed interface for reading HTTP request data. It handles input parsing, content negotiation, and file uploads for both PHP-FPM and OpenSwoole environments.

## Overview

`Request` extends Symfony's `HttpFoundation\Request` but treats it as **immutable**: every mutator returns a new instance, and `__clone()` deep-clones all parameter bags to keep clones fully isolated. This is critical for OpenSwoole coroutines, where multiple requests can interleave in the same PHP process. Work always with the return value of `with*()` methods; the original request is never modified.

## Creating a Request

### From PHP superglobals (SAPI)

```php
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;

// Capture the current $_GET, $_POST, $_COOKIE, etc.
$request = Request::capture();
```

### From components (Swoole, tests)

```php
// Useful when working with event-driven servers or in tests
$request = Request::build(
    method: 'POST',
    path: '/api/invoices',
    headers: ['Content-Type' => 'application/json'],
    query: ['page' => '1'],
    body: ['title' => 'Invoice #1'],
    rawBody: '{"title":"Invoice #1"}',
);
```

### From a Symfony Request

```php
$symfonyRequest = \Symfony\Component\HttpFoundation\Request::createFromGlobals();
$request = Request::createFromBase($symfonyRequest);
```

## Reading the Request

### Core accessors

```php
$request->method();        // 'GET', 'POST', 'PUT', etc. (always upper-case)
$request->path();          // '/api/invoices' (with leading slash)
$request->decodedPath();   // normalized, percent-decoded, no trailing slash (except '/
')
$request->rawBody();       // raw request body as string
$request->isMethod('post'); // boolean type check (case-insensitive)
$request->isSecure();      // true if HTTPS (respects X-Forwarded-Proto)
$request->scheme();        // 'http' or 'https'
$request->host();          // hostname from Host header
$request->url();           // base URL (no query string)
$request->fullUrl();       // full URL including query string
```

### Input data

The **active input source** merges the request body and query string intelligently:

- For `Content-Type: application/json`, the JSON body is decoded automatically
- For GET/HEAD requests, the query string is the input
- For other methods, the POST body is the input
- Query parameters are always available

```php
// Get a single value (searches active input, then query as fallback)
$request->input('title');                 // mixed (value or null)
$request->input('page', 1);               // with default fallback

// Type-checked accessors
$request->string('title', '');            // string
$request->integer('page', 1);             // int
$request->float('rate', 0.0);             // float
$request->boolean('active', false);       // bool ("1"/"true"/"on"/"yes" → true)

// Get the parsed body only (excludes query string)
$request->body();                         // array<string, mixed>
$request->all();                          // body + query merged
$request->queryAll();                     // only query params

// Presence and content checks
$request->has('title');                   // key exists (even if empty)
$request->filled('title');                // key exists AND not '' / [] / null
$request->missing('title');               // !has()
$request->only(['title', 'amount']);      // subset of all()
$request->except(['_token', 'password']); // all() minus keys
```

### Query and POST data

```php
$request->query('page');       // ?page=...
$request->post('title');       // POST body field (not query)
$request->server('REQUEST_ID'); // $_SERVER key
```

### Headers and authentication

```php
$request->header('Accept');          // case-insensitive, returns ?string
$request->hasHeader('Authorization');
$request->headersAll();              // array<string, mixed>

$request->cookie('session_id');      // from Cookie header
$request->hasCookie('session_id');
$request->cookiesAll();              // array<string, string>

$request->bearerToken();             // extracts "Bearer XYZ..." header
```

### Content negotiation

```php
// Query Accept-* headers to pick the best response format
$locale  = $request->negotiate()->language(['en', 'fr', 'ar']);
$type    = $request->negotiate()->media(['application/json', 'text/html']);
$enc     = $request->negotiate()->encoding(['gzip', 'br']);
$charset = $request->negotiate()->charset(['utf-8', 'iso-8859-1']);
```

Each method takes the values you support and an optional `?string $default`, and returns the best match from the corresponding `Accept-*` header (or the default).

### File uploads

```php
// Get an uploaded file (works on PHP-FPM and Swoole)
$file = $request->file('avatar');    // ?UploadedFile

if ($file !== null && $file->isValid()) {
    $name = $file->clientName();       // original filename from client
    $mime = $file->clientMimeType();
    $size = $file->size();
    $ext  = $file->extension();
    
    // Move to permanent location (FPM: move_uploaded_file; Swoole: rename)
    $file->move($dir, 'avatar-' . uuid_v4() . '.' . $ext);
}
```

### User agent parsing

`$request->userAgent()` returns the raw header (`?string`). For a parsed value object, use `UserAgent`:

```php
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\UserAgent;

$ua = UserAgent::fromRequest($request);   // or UserAgent::parse($headerString)

$ua->browser();    // e.g. 'Chrome'
$ua->version();    // browser version string
$ua->platform();   // e.g. 'macOS', 'Android'
$ua->isMobile();   // bool
$ua->isRobot();    // bool: known crawler/bot token
$ua->isBrowser();  // bool: a known browser token and not a bot
$ua->isEmpty();    // bool: no User-Agent sent
$ua->raw();        // the original string (also via (string) $ua)
```

User-Agent is client-supplied. Use it for analytics and presentation, never for an access decision.

### HTTP method enum

`Method` is a string-backed enum (`GET`, `HEAD`, `POST`, `PUT`, `PATCH`, `DELETE`, `OPTIONS`, `TRACE`, `CONNECT`):

```php
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Method;

$m = Method::from($request->method());
$m->isSafe();        // GET, HEAD, OPTIONS, TRACE
$m->isIdempotent();  // the safe methods plus PUT and DELETE
$m->isCacheable();   // GET and HEAD
Method::all();       // list of every method string
```

### Path matching and segments

```php
$request->segments();           // ['api', 'invoices'] non-empty parts
$request->segment(1);           // 'api' (1-indexed)
$request->is('api/*');          // shell-style wildcard match
$request->is('admin', 'staff'); // match any pattern
```

### JSON/AJAX detection

```php
$request->isJson();             // Content-Type contains /json or +json
$request->wantsJson();          // Accept header prefers JSON
$request->expectsJson();        // isJson() || wantsJson() || isXmlHttpRequest()
$request->isXmlHttpRequest();   // X-Requested-With: XMLHttpRequest
$request->accepts(['application/json', 'text/csv']); // pick best type or first
```

### Kernel-set attributes

```php
$request->identity();           // ?Identity (set by SecurityGateway)
$request->container();          // ?ModuleContainer (set by LoadStage)
$request->attribute('domain');  // arbitrary value from pipeline stages
```

## Immutable Mutators

Every mutator returns a **new** request. The original is never modified.

```php
$req2 = $request->withHeader('X-Custom', 'value');
$req3 = $request->withAttribute('locale', 'fr');
$req4 = $request->withIdentity($identity);
$req5 = $request->withContainer($container);

// Set multiple attributes at once (avoids multiple clones)
$req6 = $request->withAttributes([
    'domain' => $domain,
    'route_entry' => $entry,
]);

// Merge into active input (add to body/query)
$req7 = $request->merge(['source' => 'import']);

// Replace active input (replace body/query entirely)
$req8 = $request->replace(['only' => 'these']);
```

## URL and Navigation

### Building URLs from the current request

```php
// Immutable PSR-7 URI (safe manipulation without string surgery)
$loginUri = $request->uri()
    ->withPath('/login')
    ->withQuery('');

// Absolute URLs (never hardcode the host)
$callback = $request->site()->to('auth/callback');
$reset    = $request->site()->to('password/reset', ['token' => $t]);
```

`site()` returns a `SiteUri` with `base()` (the scheme and host), `to($path, $query = [])`, `asset($path)` (same as `to()`, for readability), and `uri($path)` (a PSR-7 `Uri` for further changes). `Uri` implements PSR-7's `UriInterface` in full: every `get*()` accessor and `with*()` mutator, each returning a new instance.

### Request properties as values

```php
// IP address
$request->ip();                 // the connecting peer's address (REMOTE_ADDR)

// Content type
$request->contentType();        // e.g. 'application/json; charset=utf-8'

// Files and attributes
$request->hasFile('avatar');    // bool: an upload exists under that key
$request->attributesAll();      // every request attribute (route_entry, domain, ...)
```

::: warning `ip()` behind a proxy: set `TRUSTED_PROXIES`
`ip()` delegates to Symfony's `getClientIp()`, which trusts `X-Forwarded-For` only from proxies registered with `Request::setTrustedProxies()`. Behind nginx, a load balancer or a CDN, the TCP peer is the proxy, so with no proxies trusted every request appears to come from the proxy, and anything keyed on client IP (rate limits, audit trails, allow-lists) sees one user.

Generated projects read `TRUSTED_PROXIES` in their entry points (`app/public/index.php`, `app/swoole/index.php`): a comma-separated list of proxy IPs or CIDRs, or `PRIVATE_SUBNETS`; under PHP-FPM also `REMOTE_ADDR` (trust the immediate peer, which is safe only when nothing but the proxy can reach PHP). `X-Forwarded-For`, `-Proto` and `-Port` are then honoured **from those proxies only**. Left empty, nothing is trusted and a forged `X-Forwarded-For` is ignored. Never trust a range a client can connect from directly. Prefer the proxies' real addresses: `PRIVATE_SUBNETS` is Symfony's list, which is wider than RFC 1918. It also covers carrier-grade NAT (`100.64.0.0/10`) and reserved ranges, so a client on a CGNAT address that can reach PHP directly could set its own `X-Forwarded-For`.
:::

## Uploaded Files

### UploadedFile API

```php
// createFromBase: PHP-FPM (wraps Symfony UploadedFile with move_uploaded_file safety)
$file = UploadedFile::createFromBase($symfonyFile);

// fromSwoole: Swoole temp files (test mode, uses rename())
$file = UploadedFile::fromSwoole($_FILES['avatar']);

// Properties
$file->clientName();            // original filename
$file->clientMimeType();        // e.g. 'image/jpeg'
$file->size();                  // bytes
$file->extension();             // lowercase (from client name)
$file->tempPath();              // current temp path
$file->contents();              // read file as string
$file->isValid();               // passed basic checks

// Move to permanent location (SAPI vs Swoole safe)
$file->move($dir, $name);
```

### Working with file uploads

```php
final class AvatarController
{
    public function upload(Request $request, StoragePort $storage): Response
    {
        $file = $request->file('avatar');
        
        if ($file === null || !$file->isValid()) {
            throw new ValidationException(['avatar' => 'File is required']);
        }
        
        if (!in_array($file->extension(), ['jpg', 'png', 'gif'])) {
            throw new ValidationException(['avatar' => 'Only JPG, PNG, GIF allowed']);
        }
        
        $path = $storage->store(
            $file->contents(),
            $file->clientName(),
            'avatars',
        );
        
        return Response::json(['url' => $path], 201);
    }
}
```

## Immutability and OpenSwoole Safety

### Why deep cloning matters

`Request::__clone()` deep-clones all seven parameter bags (query, request, attributes, cookies, files, server, headers). Without this:

- A `with*()` mutation in one coroutine would leak into another
- Two concurrent requests would share the same parameter objects
- The isolation guarantees of immutability would vanish at the byte level

```php
$r1 = $request->withAttribute('user_id', 1);
$r2 = $request->withAttribute('user_id', 2);

// Even under concurrent execution, r1 and r2 are completely independent
// The original $request still has no attributes
```

### Chaining is safe

```php
$req = $request
    ->withAttribute('locale', 'fr')
    ->withAttribute('user_id', 42)
    ->withHeader('X-Trace', $id);

// No intermediate requests leak out, and each step produces an independent copy
```

## Common Patterns

### DTO construction from request

```php
final readonly class CreateInvoiceDTO
{
    public function __construct(
        public readonly string $title,
        public readonly string $currency,
        public readonly int $amount,
    ) {}
    
    public static function fromRequest(Request $request): self
    {
        return new self(
            title:    $request->string('title'),
            currency: $request->string('currency', 'USD'),
            amount:   $request->integer('amount', 0),
        );
    }
}

// In a controller
$dto = CreateInvoiceDTO::fromRequest($request);
```

### Request validation

```php
$errors = [];

if (!$request->filled('email')) {
    $errors['email'] = 'Email is required';
}
if (!filter_var($request->string('email'), FILTER_VALIDATE_EMAIL)) {
    $errors['email'] = 'Email is invalid';
}

if ($errors !== []) {
    throw new ValidationException($errors);
}
```

### Conditional request handling

```php
// Respond with JSON or HTML based on the client
if ($request->expectsJson()) {
    return Response::json($data);
}

return Response::html($this->render('template', $data));
```

## Common Mistakes

::: danger Never mutate the request

```php
// ✗ WRONG
$request->request->set('source', 'import');

// ✓ Correct
$request = $request->merge(['source' => 'import']);
```

The `request` property is mutable, but doing so bypasses immutability guarantees and will cause data loss under OpenSwoole.

:::

::: danger Never pass the original to a stage

```php
// ✗ WRONG — you'll lose any mutations the stage makes
$response = $this->someStage->handle($request, $next);

// The stage should always be called through the pipeline
```

:::

::: info JSON body is decoded automatically

When `Content-Type` contains `/json` or `+json`, the body is automatically decoded into the active input. Use `$request->input()` or `$request->all()` to access it, not `json_decode()`.

:::

## Source

- [Request.php](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/modules/http/src/Request.php)
- [UploadedFile.php](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/modules/http/src/UploadedFile.php)
- [Uri.php](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/modules/http/src/Uri.php)
- [SiteUri.php](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/modules/http/src/SiteUri.php)
- [Negotiate.php](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/modules/http/src/Negotiate.php)
- [UserAgent.php](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/modules/http/src/UserAgent.php)
