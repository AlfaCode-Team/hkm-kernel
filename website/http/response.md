# Response

The `Response` class provides a fluent, immutable interface for building HTTP responses. It supports JSON, HTML, streaming, file downloads, and proper cookie management for both PHP-FPM and OpenSwoole.

## Overview

`Response` is built via **named constructors** (`json()`, `empty()`, `notFound()`, etc.) rather than a general constructor. Every response is **immutable**: `withHeader()`, `withCookie()`, `withStatus()` return new instances without modifying the original. Cookies are managed through Symfony's `ResponseHeaderBag` so both SAPI and Swoole adapters emit them correctly.

## Named Constructors

### Success responses

```php
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;

Response::json(['id' => 1, 'name' => 'Invoice']);     // 200
Response::json($data, 201);                          // with custom status
Response::success(['data' => $user]);                // 200 + { "success": true, "data": {...} }
Response::created($dto, location: "/api/items/123"); // 201 + Location header
Response::accepted(['job_id' => 'job-xyz']);         // 202 Accepted (async)
Response::noContent();                               // 204 No Content
Response::empty(200);                                // any 2xx/3xx with no body
```

### Error responses

```php
Response::badRequest('Invalid format');
Response::unauthorized('Token expired');
Response::forbidden('You lack permission');
Response::notFound('Invoice not found');
Response::conflict('Resource already exists');
Response::tooManyRequests('Rate limit exceeded', retryAfter: 60);
Response::unprocessable(['email' => 'Already taken', 'name' => 'Required']);
Response::serverError('Something went wrong');
```

All error responses use the standard error envelope:

```json
{
  "error": {
    "code": "not_found",
    "message": "Resource not found.",
    "requestId": "20250615-a3f8b2c1",
    "fields": { "id": "Not found" }
  }
}
```

Note: `requestId` is always included (correlation ID for tracing); `fields` appears only on 422 validation errors.

### Content types

```php
Response::json(['key' => 'value']);           // application/json
Response::text('plain text');                 // text/plain; charset=utf-8
Response::html('<h1>Hello</h1>');            // text/html; charset=utf-8
Response::jsonp('callback', ['data' => 1]);  // application/javascript (JSONP)
```

### Redirects

```php
Response::redirect('/login');               // 302 Found (temporary)
Response::permanentRedirect('/new-url');    // 301 Moved Permanently (cacheable)
Response::back($referer);                   // redirect to Referer header or fallback
Response::back($request->header('referer'), fallback: '/');
```

## Immutable Mutators

Every mutator returns a **new** response:

```php
$res = Response::json($data)
    ->withHeader('Cache-Control', 'no-store')
    ->withHeader('X-Custom', 'value')
    ->withHeaders(['X-A' => 'val1', 'X-B' => 'val2'])
    ->withStatus(201)
    ->withCookie('session', 'abc123', maxAge: 3600)
    ->withoutCookie('old_cookie');
```

### Cookie helpers

```php
// Queue a Set-Cookie header (secure defaults)
$response->withCookie(
    name: 'session_id',
    value: 'abc123',
    maxAge: 3600,              // seconds (0 = session cookie)
    path: '/',                 // default
    domain: null,              // default (current domain)
    secure: true,              // HTTPS only (default)
    httpOnly: true,            // no JS access (default)
    sameSite: 'Lax',           // CSRF protection (default)
);

// Expire a cookie immediately
$response->withoutCookie('old_id');
```

### Status and body

```php
$response->withStatus(201);    // change HTTP status code
$response->status();           // read status code
$response->body();             // read body as string
$response->headers();          // array<name => value> (excludes cookies)
$response->cookies();          // string[] raw Set-Cookie lines (for Swoole)
```

## Streaming and Files

### Stream output (no buffering)

For large outputs (CSV export, log download), stream via a callback:

```php
Response::stream(function () {
    // This callback is invoked at send() time, line by line.
    // The output buffer is flushed every 8KB automatically.
    echo "row1,data1\n";
    echo "row2,data2\n";
    // etc.
}, status: 200);

// Or with a custom content type
Response::stream(
    fn () => /* ... */,
    headers: ['Content-Type' => 'text/csv', 'Content-Disposition' => 'attachment; filename="export.csv"']
);

// Stream a file download (generated on-the-fly)
Response::streamDownload(
    fn () => echo file_get_contents('/path/to/file'),
    name: 'report.pdf'
);
```

### File downloads and inline viewing

```php
// Force download (Content-Disposition: attachment)
Response::download('/path/to/file.pdf');
Response::download('/path/to/file.pdf', name: 'invoice.pdf');

// Serve inline (render in browser if supported)
Response::file('/path/to/image.jpg');
Response::file('/path/to/document.pdf', name: 'document.pdf');
```

## Transport-Agnostic Emission

### SAPI (traditional PHP-FPM)

```php
// Just call send()
$response->send();
```

### Swoole and other async servers

Swoole adapters don't call `send()` directly. Instead, they inspect:

```php
// Check response type
$response->isStreamed();       // callback-based streaming
$response->isFile();           // file-based streaming

// Get file path for sendfile() optimization
$response->filePath();         // absolute path or null

// Emit streamed output in chunks
$response->streamTo($writer);  // writes chunks via $writer() callback

// Or materialize the entire body
$response->body();             // string, materializes file or stream
```

The generated OpenSwoole entry point (`app/swoole/index.php`) emits it like this:

```php
$response = $kernel->http()->handle($request);

if ($response->isFile() && $response->filePath() !== null && is_file($response->filePath())) {
    // sendfile(): zero-copy, and it finalises the response itself
    $swooleResponse->sendfile($response->filePath());
} else if ($response->isStreamed()) {
    // Stream in chunks
    $response->streamTo(fn(string $chunk) => $swooleResponse->write($chunk));
} else {
    // Send the body normally
    $swooleResponse->end($response->body());
}
```

## ManagesResponse Trait

The `ManagesResponse` trait provides the immutable mutator methods (`withHeader()`, `withCookie()`, `withStatus()`). Both `Response` and any framework-specific response type can use it.

## Standard Error Envelope

All error responses follow this shape:

```json
{
  "error": {
    "code": "error_code",
    "message": "Human-readable message",
    "requestId": "20250615-a3f8b2c1",
    "fields": { "key": ["error1", "error2"] }
  }
}
```

- `code`: machine-readable error identifier
- `message`: what went wrong (safe for logs and APIs)
- `requestId`: correlation ID (for tracing)
- `fields`: field-level validation errors (only on 422)

## Streaming vs In-Memory

### When to stream

Use streaming for:

- Large exports (CSV with 100k rows)
- Dynamic content (PDF generation)
- Infinite/long-lived streams (event sources)

```php
Response::stream(function () use ($rows) {
    foreach ($rows as $row) {          // $rows: any iterable, ideally a generator
        echo $row['name'] . "\n";      // written as it is produced, never buffered whole
    }
});
```

### Why streaming matters

Without streaming, a large file must fit entirely in PHP memory (`memory_limit`). With streaming, only a few KB of buffer exists at once.

```php
// ✗ Don't do this for large files
$csv = fopen('export.csv', 'w');
foreach ($rows as $row) fputcsv($csv, $row);
fseek($csv, 0);
$body = stream_get_contents($csv);  // HUGE memory spike
return Response::text($body);

// ✓ Use streaming instead
return Response::stream(function () use ($rows) {
    foreach ($rows as $row) {
        echo implode(',', $row) . "\n";
    }
});
```

## FPM vs OpenSwoole Compatibility

### File downloads

Both SAPI and Swoole need special handling:

```php
// FPM: readfile() copies the file to the output buffer
// Swoole: sendFile() uses the sendfile syscall (zero-copy)
$response = Response::download($path);
$response->send();  // FPM: works
// Swoole adapter checks $response->filePath() and uses sendfile()
```

### Streaming

```php
// FPM: ob_start callback + callback invocation
// Swoole: buffered writes to the response via $writer
$response = Response::stream(fn() => echo "data");
$response->send();  // FPM: works
// Swoole adapter calls streamTo($writer)
```

### Cookies

```php
// FPM: setcookie() header
// Swoole: raw Set-Cookie header line via $res->header()
$response->withCookie('id', 'abc');
$response->send();  // FPM: works
// Swoole adapter reads $response->cookies() for each Set-Cookie line
```

## Common Patterns

### Conditional response based on Accept header

```php
final class ReportController
{
    public function show(Request $request): Response
    {
        $data = $this->getReport();
        
        return match ($request->accepts(['application/json', 'text/csv'])) {
            'application/json' => Response::json($data),
            'text/csv' => Response::stream(
                fn () => $this->writeCsv($data),
                headers: ['Content-Type' => 'text/csv']
            ),
            default => Response::html($this->render('report', $data)),
        };
    }
}
```

### Export with proper headers

```php
$response = Response::streamDownload(
    fn () => foreach ($rows as $row) echo implode(',', $row) . "\n",
    name: 'export.csv'
);

return $response->withHeader('Cache-Control', 'no-store');
```

### Created resource

```php
return Response::created(
    $user->toArray(),
    location: "/api/users/{$user->id}",
)->withCookie('auth_token', $token, maxAge: 86400);
```

## Common Mistakes

::: danger Don't buffer large files

```php
// ✗ WRONG — memory_limit spike
$body = file_get_contents($path);
return Response::text($body);

// ✓ Correct
return Response::file($path);
```

:::

::: danger Don't call send() on a Swoole adapter

```php
// ✗ In a Swoole context, send() doesn't reach the client
$response->send();

// The Swoole adapter calls streamTo() / filePath() / body() instead
```

:::

::: danger Cookies are immutable

```php
// ✗ WRONG
$response->withCookie('id', 'abc');  // discarded, not chained

// ✓ Correct
$response = $response->withCookie('id', 'abc');
```

:::

::: info Use named constructors, not the general constructor

```php
// ✗ Don't construct manually
new Response('{"data":[]}', 200, ['Content-Type' => 'application/json']);

// ✓ Use the named constructor
Response::json(['data' => []]);
```

:::

## Source

- [Response.php](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/modules/http/src/Response.php)
- [ManagesResponse.php](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/modules/http/src/Concerns/ManagesResponse.php)
