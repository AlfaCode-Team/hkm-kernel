# HKM Kernel — HTTP Controller Layer

> Controllers are **thin wrappers**. They translate HTTP requests into DTOs,
> call a service, and translate the result into an HTTP response.
> Three lines is the ideal controller method. Five lines is acceptable. More is a design smell.

---

## Controller Rules (ABSOLUTE)

| Rule | Detail |
|---|---|
| Calls Service ONLY via published contract | Never Repository, Gateway, or Domain directly |
| Input → DTO conversion here | `fromRequest()` validates and shapes the input |
| Returns `Response` objects | Never echoes, exits, or dies |
| Zero business logic | If it isn't HTTP translation, it belongs in the Service |
| Zero authorization decisions | Authorization belongs in the Service layer |
| Receives Identity via constructor | Injected by scoped container — set by SecurityGateway |

---

## Canonical Controller

```php
<?php
declare(strict_types=1);

namespace InvoiceModule\Infrastructure\Http\Controllers;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\{Request, Response};
use InvoiceModule\API\Contracts\InvoiceServiceContract;
use InvoiceModule\Application\DTO\{CreateInvoiceDTO, ListInvoicesDTO};

final class InvoiceController
{
    public function __construct(
        private readonly InvoiceServiceContract $service, // published contract only
    ) {}

    // GET /api/invoices
    public function index(Request $request): Response
    {
        $dto    = ListInvoicesDTO::fromRequest($request);
        $result = $this->service->list($dto);
        return Response::json($result->toArray());
    }

    // POST /api/invoices
    public function create(Request $request): Response
    {
        $dto    = CreateInvoiceDTO::fromRequest($request); // validation here
        $result = $this->service->create($dto);            // business logic in service
        return Response::json($result->toArray(), 201);
    }

    // GET /api/invoices/{id}
    public function show(Request $request, string $id): Response
    {
        $result = $this->service->find($id);
        return Response::json($result->toArray());
    }

    // PUT /api/invoices/{id}
    public function update(Request $request, string $id): Response
    {
        $dto    = UpdateInvoiceDTO::fromRequest($request, $id);
        $result = $this->service->update($dto);
        return Response::json($result->toArray());
    }

    // DELETE /api/invoices/{id}
    public function destroy(Request $request, string $id): Response
    {
        $this->service->delete($id);
        return Response::empty(204);
    }
}
```

---

## Response Factory Methods

```php
Response::json($data, 200)          // 200 OK — data as JSON body
Response::json($data, 201)          // 201 Created
Response::empty(204)                // 204 No Content — no body
Response::notFound()                // 404 {"error":{"code":"not_found",...}}
Response::unauthorized()            // 401
Response::forbidden()               // 403
Response::unprocessable($errors)    // 422 {"error":{"code":"validation_failed","fields":{...}}}
Response::serverError()             // 500
Response::redirect($url, 302)       // 302 Redirect
Response::created($data, $location) // 201 + Location header
Response::download($path, $name)    // file download (sendfile on Swoole)
Response::stream($callback)         // chunked streaming (both transports)
```

---

## Standard Response Shapes

### Success (200/201)
```json
{
  "invoiceId": "inv_01H9X...",
  "number":    "INV-2025-000001",
  "status":    "issued",
  "total":     300.00,
  "currency":  "USD"
}
```

### Paginated List (200)
```json
{
  "data": [ { ... }, { ... } ],
  "meta": { "total": 142, "page": 2, "per_page": 20, "last_page": 8 },
  "links": { "prev": "...", "next": "...", "first": "...", "last": "..." }
}
```

### Validation Error (422)
```json
{
  "error": {
    "code":      "validation.failed",
    "message":   "The request data is invalid.",
    "requestId": "20250615-a3f8b2c1",
    "fields": {
      "dueDate":               ["The due date must be a future date."],
      "lineItems":             ["At least one line item is required."],
      "lineItems.0.unitPrice": ["Unit price must be greater than zero."]
    }
  }
}
```

### Service / Auth Error (4xx/5xx)
```json
{
  "error": {
    "code":      "invoice.not_found",
    "message":   "Invoice [inv_123] was not found.",
    "requestId": "20250615-b4c9d3e2"
  }
}
```

---

## Request (most-used surface)

`Request` is a final, IMMUTABLE value object (Symfony HttpFoundation under the hood — see
the HTTP layer usage in the [project README](../../README.md) for the full method list). The
methods controllers/DTOs reach for:

```php
$request->method();                  // 'POST'           (upper-case)
$request->path();                    // '/api/invoices'  (leading slash, no query)
$request->body();                    // parsed BODY only (JSON/form), excludes query
$request->all();                     // body + query merged
$request->input($key, $default);     // single value (body or query)
$request->only([...]); $request->except([...]);
$request->boolean($k); $request->integer($k); $request->float($k); $request->string($k);
$request->query($key, $default);     // query-string value
$request->header($name);             // ?string, case-insensitive
$request->cookie($name);             // ?string
$request->bearerToken();             // Authorization: Bearer …
$request->file($key);                // ?UploadedFile  (FPM + Swoole safe)
$request->expectsJson();             // negotiate JSON vs HTML
$request->identity();                // ?Identity      (from SecurityGateway)
$request->attribute('domain');       // ?DomainContext (from the pipeline)

// URL / negotiation helpers:
$request->uri();                     // immutable PSR-7 Uri  → withPath()/withQuery()
$request->site()->to('auth/callback'); // absolute URL, host from the request
$request->negotiate()->language(['en','fr']);
```

Immutable mutators return a NEW request — `$request = $request->withAttribute('k', $v)`,
`->merge([...])`, `->withHeader(...)`. NEVER mutate in place (Swoole-safety).

---

## DTO Validation in `fromRequest()`

```php
final readonly class CreateInvoiceDTO
{
    public static function fromRequest(Request $request): self
    {
        $data   = $request->body();
        $errors = [];

        // Validate each field — collect ALL errors before throwing
        if (empty($data['clientId'])) {
            $errors['clientId'] = 'Client ID is required';
        }

        if (empty($data['dueDate'])) {
            $errors['dueDate'] = 'Due date is required';
        } else {
            try {
                $due = new \DateTimeImmutable($data['dueDate']);
                if ($due <= new \DateTimeImmutable()) {
                    $errors['dueDate'] = 'Due date must be in the future';
                }
            } catch (\Exception) {
                $errors['dueDate'] = 'Due date must be a valid date (YYYY-MM-DD)';
            }
        }

        if (empty($data['lineItems'])) {
            $errors['lineItems'] = 'At least one line item is required';
        }

        if (!empty($errors)) {
            throw new ValidationException($errors); // → 422 response
        }

        return new self(
            clientId:  $data['clientId'],
            dueDate:   $data['dueDate'],
            lineItems: $data['lineItems'],
        );
    }
}
```

---

## File Upload in Controller

```php
public function upload(Request $request): Response
{
    $file = $request->file('document');

    if (!$file || !$file->isValid()) {
        return Response::unprocessable(['document' => 'Invalid or missing file']);
    }

    // Pass the file to the service — service validates type/size
    $result = $this->service->store(new StoreDocumentDTO(
        file:       $file,
        uploadedBy: $request->identity()->userId,
    ));

    return Response::json(['documentId' => $result->id, 'path' => $result->path], 201);
}
```

---

## Base Controllers (project layer — `Project\Http\Controllers\`)

Two optional base classes live in `projects/Http/Controllers/` (namespace
`Project\`). They are project-layer, NOT kernel, because view rendering and
cookies are plugin concerns — the kernel stays renderer-agnostic.

| Base | Use for | Coupling |
|---|---|---|
| `ApiController` | JSON endpoints | Pure kernel types (no plugin) |
| `ViewController` | HTML/view endpoints | Injects `ViewRendererContract` (View plugin) |

`ApiController` helpers: `ok()`, `created()`, `accepted()`, `noContent()`,
`paginated()`, `okOrNotFound()`, `notFound()`, `forbidden()`, `unprocessable()`,
`identity()`. `ViewController` helpers: `view()`, `viewNotFound()`, `redirect()`,
`back()`.

Both `use InteractsWithCookies` (trait wrapping every public `CookieJar` method:
`cookie()`, `queueCookie()`, `rememberCookie()`, `forgetCookie()`,
`hasQueuedCookie()`, `decryptCookie()`, `cookieJar()`).

### RequestAware — actions take route params ONLY (no `$request`)

Both bases implement the kernel contract
`AlfacodeTeam\…\Kernel\Http\Contracts\RequestAware` (`setRequest(Request): static`).
`ExecuteStage` detects it and:

- calls `setRequest($request)` with the container-bearing request BEFORE the action, then
- invokes the action as `$method(...$routeParams)` — WITHOUT `$request`.

Plain controllers (not `RequestAware`) keep the conventional
`$method($request, ...$params)` signature — fully backward compatible.

```php
use Project\Http\Controllers\ApiController;

final class CartController extends ApiController        // RequestAware
{
    public function show(string $id): Response          // route param only — no $request
    {
        $this->queueCookie('last_viewed', $id);         // request injected by the kernel
        return $this->okOrNotFound($this->cart->find($id)?->toArray());
    }
}
```

The raw request is still available inside the action as `$this->request`; any
cookie helper also accepts an explicit `?Request` override.

---

## Rules for Controller Code

When writing or reviewing controller code:

- **DO** keep every controller method to 3–5 lines: DTO → service → response
- **DO** put all validation logic in `DTO::fromRequest()` — not in the controller method
- **DO** use `Response::json($data, 201)` for create operations
- **DO** use `Response::empty(204)` for delete and void operations
- **DON'T** inject `InvoiceRepository` or any repository into a controller
- **DON'T** put authorization logic in controllers — it belongs in the service
- **DON'T** put business logic in controllers — even one if-statement is too much
- **DON'T** catch exceptions in controllers — let the `ErrorStage` handle them
- **DON'T** use `echo`, `print`, `exit`, `die`, or `header()` in controllers
- **DON'T** instantiate domain entities in controllers
