# Controllers

Controllers are request handlers that translate an HTTP request into a domain operation and return a response. The kernel enforces a **strict 3-line rule**: validate input, call the service, return a response. All business logic stays in the service layer.

## The 3-Line Controller Rule

Every controller action should be ≤3 lines:

1. Transform request into a DTO (or direct parameter extraction)
2. Call the service layer with the DTO
3. Translate the result into a Response

```php
<?php
declare(strict_types=1);

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\{Request, Response};

final class InvoiceController
{
    public function __construct(
        private readonly InvoiceServiceContract $service,
    ) {}

    public function create(Request $request): Response
    {
        $dto = CreateInvoiceDTO::fromRequest($request);  // validate
        $result = $this->service->create($dto);          // execute
        return Response::created($result->toArray());    // respond
    }

    public function show(Request $request, string $id): Response
    {
        $result = $this->service->find($id);
        return $result !== null ? Response::json($result->toArray()) : Response::notFound();
    }

    public function destroy(Request $request, string $id): Response
    {
        $this->service->delete($id);
        return Response::empty(204);
    }
}
```

### Why 3 lines?

This rule enforces separation of concerns:

- **Request handling** (DTO construction, validation) — controller only
- **Business logic** (authorization, state changes, events) — service only
- **HTTP translation** (status codes, headers) — controller only

A controller longer than 3 lines almost always contains business logic that belongs in the service layer.

## Route Parameter Passing

The framework extracts route parameters and passes them to the action **positionally, in the order the placeholders appear in the path**. `ExecuteStage` calls `array_values()` on the captured parameters, so your argument names are documentation only: `{userId}/roles/{roleId}` arrives as the first and second parameters whatever you call them. Reordering the arguments silently swaps the values.

An optional placeholder that is absent arrives as `''`, so give the argument a `string` type, not `?string` with a `null` default.

```php
// route: POST /api/invoices/{id:num}
public function update(Request $request, string $id): Response
{
    // $id is the {id:num} from the path
    $dto = UpdateInvoiceDTO::fromRequest($request);
    $result = $this->service->update($id, $dto);
    return Response::json($result->toArray());
}

// Multiple params
// route: DELETE /api/users/{userId:num}/roles/{roleId:num}
public function removeRole(Request $request, string $userId, string $roleId): Response
{
    $this->service->removeRole($userId, $roleId);
    return Response::empty(204);
}
```

Parameters are always strings (from the URL). Type-check them if you need integers:

```php
$userId = (int) $userId;
```

## RequestAware: Alternative to $request Parameter

Controllers that need to hold the request can implement `RequestAware`:

```php
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Contracts\RequestAware;

final class DashboardController implements RequestAware
{
    private Request $request;

    public function setRequest(Request $request): static
    {
        $this->request = $request;
        return $this;
    }

    // Actions take ONLY route params, not $request
    public function show(string $userId): Response
    {
        $locale = $this->request->attribute('locale');
        $result = $this->service->dashboard($userId, $locale);
        return Response::html($this->render('dashboard', $result));
    }
}
```

`ExecuteStage` calls `setRequest()` with the same request the action receives (the one carrying the request-scoped container), then invokes the action without passing `$request` as a parameter.

Use `RequestAware` when:

- You need cookies (cookie helpers resolve via the request)
- You need per-request state set by hooks
- You want a cleaner action signature

## Dependency Injection

Controllers are resolved via the request-scoped container, so you can inject:

- **Ports** (DatabasePort, CachePort, StoragePort, MailPort, etc.)
- **Published service contracts** (if the route's module requires the provider module)
- **Framework services** (TransactionManager, EventBus, Identity, etc.)

```php
final class InvoiceController
{
    public function __construct(
        private readonly InvoiceServiceContract $service,  // published contract
        private readonly DatabasePort          $db,        // port
        private readonly Identity              $identity,  // from SecurityGateway
    ) {}

    public function create(Request $request): Response
    {
        // Services injected at construction time
        $dto = CreateInvoiceDTO::fromRequest($request);
        $result = $this->service->create($dto);
        return Response::created($result->toArray());
    }
}
```

### Scoped vs App-Lifetime Dependencies

- **Request-scoped** (ModuleContainer): services, repositories, domain logic — **always use these**
- **App-lifetime** (CoreContainer): ports, Kernel — typically for infrastructure

Controllers are request-scoped, so they're never instantiated for long-lived requests (good for memory). But anything you store in the controller must be thrown away after the request.

## DTO Validation Pattern

DTOs are where validation lives:

```php
<?php
declare(strict_types=1);

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ValidationException;

final readonly class CreateInvoiceDTO
{
    public function __construct(
        public readonly string $title,
        public readonly string $currency,
        public readonly int $amount,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $errors = [];

        if (!$request->filled('title')) {
            $errors['title'] = 'Title is required';
        }
        if ($request->string('title') && strlen($request->string('title')) > 255) {
            $errors['title'] = 'Title must be ≤255 characters';
        }

        if (!$request->filled('currency') || !in_array($request->string('currency'), ['USD', 'EUR', 'GBP'])) {
            $errors['currency'] = 'Currency must be USD, EUR, or GBP';
        }

        if ($request->integer('amount') <= 0) {
            $errors['amount'] = 'Amount must be > 0';
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return new self(
            title: $request->string('title'),
            currency: $request->string('currency'),
            amount: $request->integer('amount'),
        );
    }
}
```

`ValidationException` is caught by the error pipeline and turned into a 422 response with field-level errors.

## Project Controllers

Controllers in the project layer (not a plugin) are resolved under the **`__project__` scope**, which has NO transitive module dependencies. They can access:

- Ports
- Framework services (Identity, TransactionManager, etc.)
- Services from modules they explicitly require via route-level `requires[]`

```jsonc
{
  "routes": [
    {
      "method": "GET",
      "path": "/dashboard",
      "handler": "Project\\Http\\DashboardController@show",
      "requires": ["view.rendering"]  // opt this route into the view module
    }
  ]
}
```

```php
// Project\Http\DashboardController (in the project, not a plugin)
final class DashboardController
{
    public function __construct(
        private readonly ViewRendererContract $renderer,  // from view.rendering module
        private readonly DatabasePort        $db,         // port
    ) {}

    public function show(): Response
    {
        $data = $this->db->query('SELECT * FROM dashboard_data');
        return Response::html($this->renderer->render('dashboard', $data));
    }
}
```

## Error Handling in Controllers

Respond with appropriate status codes or throw exceptions for business rule violations:

```php
public function show(string $id): Response
{
    $invoice = $this->service->find($id);
    
    // Return 404 when not found
    return $invoice !== null ? Response::json($invoice->toArray()) : Response::notFound();
}

// Throw for business rule violations
public function create(Request $request): Response
{
    $dto = CreateInvoiceDTO::fromRequest($request);  // throws ValidationException if invalid
    $result = $this->service->create($dto);           // throws ServiceException if business rules fail
    return Response::created($result->toArray());
}
```

The error pipeline catches exceptions and:

1. Classifies them (SecurityException → 401, ValidationException → 422, etc.)
2. Logs them (with severity based on type)
3. Returns a standardized error response

## Conditional Responses

```php
public function show(Request $request, string $id): Response
{
    $invoice = $this->service->find($id);
    
    // Respond based on Accept header
    return match ($request->accepts(['application/json', 'text/html'])) {
        'application/json' => Response::json($invoice->toArray()),
        'text/html' => Response::html($this->render('invoice', $invoice)),
        default => Response::json($invoice->toArray()),
    };
}
```

## Response Types

Always return `Response` (from the kernel):

```php
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;

public function create(Request $request): Response
{
    return Response::created($data);  // ✓ Correct type
}

// ✗ Don't return Symfony responses — they won't be instanceof Response
// and the pipeline's type checks will fail
```

## HttpStatusAware Interface

If your controller needs to signal a specific status for conditional responses:

```php
// (This is a hypothetical — it doesn't exist in the kernel yet, but the
// pattern is valid for controllers that want to influence status codes.)

interface HttpStatusAware
{
    public function httpStatus(): int;
}
```

Most controllers just use the named constructors (created, notFound, etc.) and never need this.

## Common Patterns

### Update with optimistic locking

```php
public function update(Request $request, string $id): Response
{
    $dto = UpdateInvoiceDTO::fromRequest($request);
    
    try {
        $result = $this->service->update($id, $dto);
    } catch (OptimisticLockException $e) {
        throw new ValidationException(['version' => 'Resource was modified']);
    }
    
    return Response::json($result->toArray());
}
```

### Bulk operations

```php
public function createMany(Request $request): Response
{
    $payload = $request->body();
    
    if (!is_array($payload) || empty($payload)) {
        throw new ValidationException(['items' => 'Array of items required']);
    }
    
    $results = [];
    foreach ($payload as $item) {
        $dto = CreateItemDTO::fromArray($item);
        $results[] = $this->service->create($dto)->toArray();
    }
    
    return Response::json(['items' => $results], 201);
}
```

### File upload with validation

```php
public function upload(Request $request): Response
{
    $file = $request->file('document');
    
    if ($file === null || !$file->isValid()) {
        throw new ValidationException(['document' => 'File is required']);
    }
    
    if ($file->size() > 10_000_000) {
        throw new ValidationException(['document' => 'File too large (max 10MB)']);
    }
    
    if (!in_array($file->extension(), ['pdf', 'doc', 'docx'])) {
        throw new ValidationException(['document' => 'PDF or Word document only']);
    }
    
    $stored = $this->service->storeDocument($file);
    
    return Response::json(['url' => $stored->url()], 201);
}
```

## Common Mistakes

::: danger Don't put business logic in the controller

```php
// ✗ WRONG
public function create(Request $request): Response
{
    $data = $request->body();
    
    // Business logic belongs in the SERVICE, not here
    if ($data['amount'] > 10000) {
        $data['requires_approval'] = true;
    }
    
    $this->db->insert('invoices', $data);
    
    return Response::created();
}

// ✓ Correct
public function create(Request $request): Response
{
    $dto = CreateInvoiceDTO::fromRequest($request);
    $result = $this->service->create($dto);  // service handles all logic
    return Response::created($result->toArray());
}
```

:::

::: danger Don't bypass the DI container

```php
// ✗ WRONG
public function create(Request $request): Response
{
    // Direct instantiation bypasses testability
    $service = new InvoiceService(...);
    $service->create($dto);
}

// ✓ Correct
public function __construct(private readonly InvoiceServiceContract $service) {}
```

:::

::: danger Don't call services from the wrong scope

```php
// ✗ WRONG — PaymentService is from the payment module,
// but this controller never declared it in requires[]
$payment = $this->container->make(PaymentService::class);

// ✓ Correct — inject via constructor (DI fails if the module isn't required)
public function __construct(private readonly PaymentService $payment) {}
```

:::

::: danger Don't mutate the request

```php
// ✗ WRONG
$request->request->set('user_id', $this->identity->userId);

// ✓ Correct — if you need to carry state, use withAttribute
$request = $request->withAttribute('processed_by', 'invoice_controller');
```

:::

## Source

- [ExecuteStage.php](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Pipelines/Http/Stages/ExecuteStage.php)
- [RequestAware.php](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/modules/http/src/Contracts/RequestAware.php)
