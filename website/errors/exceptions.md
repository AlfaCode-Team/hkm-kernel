# Exception Hierarchy

The framework defines a structured exception hierarchy so callers know what went wrong and at what layer. Every exception carries context for logging and debugging. Most exceptions extend `FrameworkException`, which provides a common shape for layer identification and context data.

## FrameworkException Base Class

The abstract base class for all framework-defined exceptions:

```php
<?php declare(strict_types=1);
namespace AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions;

abstract class FrameworkException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $layer = '',
        public readonly array  $context = [],
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
```

**Attributes:**

- `$message` — what went wrong (passed to parent `\RuntimeException`)
- `$layer` — which layer threw it, e.g., `'service.invoice.create'`, `'gateway.stripe.charge'`
- `$context` — a typed array of relevant data for logs and alerting: `['clientId' => '…', 'amount' => 9999]`
- `$code` — optional HTTP status code (used by some exception types)
- `$previous` — the previous exception, if translating from another layer

## Exception Reference

| Exception | HTTP Status | Severity | Throw from | Meaning |
|-----------|-------------|----------|------------|---------|
| **`SecurityException`** | its `code` if 401, 403 or 429, else 403 | warning | Security layers, filters, services | Identity denied, insufficient permissions, token invalid |
| **`ValidationException`** | 422 | info | Controllers, request validation | Input data is invalid; client-correctable |
| **`DomainException`** (kernel) | 422 | info | Domain-facing code | Business rule violated; client-correctable |
| **`ServiceException`** | 422 | warning | Service layer | A use case failed or was refused |
| **`RepositoryException`** | 500 | critical | Repository layer | Database operation failed |
| **`GatewayException`** | 502 | critical | Gateway layer | Vendor API or external call failed |
| **`KernelException`** | 500 | critical | Kernel itself | Boot, routing, pipeline failure |
| **`OptimisticLockException`** | 409 | warning | Repository layer | Concurrent update conflict (extends `RepositoryException`) |
| **`LockTimeoutException`** | 409 | warning | `Lock::block()` | Could not acquire a lock in time |
| **`ScopeViolationException`** | 500 | critical | DI container | Internal binding resolved outside its module |
| **`CircularDependencyException`** | n/a (boot) | n/a | Boot pipeline | Cycle in `requires[]` |
| **`BootException`** | n/a (boot) | n/a | Boot stages | One stage's validation failed (wrapped in `BootFailureException`) |
| **`BootFailureException`** | n/a (boot) | n/a | `BootPipeline` | Boot failed; the application cannot start |
| **`EntryNotFoundException`** | 500 | critical | DI container | Container cannot resolve a requested id |
| **`RejectedJobException`** | n/a (worker) | warning | Worker pipeline | Dequeued job failed signature verification (extends `SecurityException`) |

How the status is chosen (`ErrorStage::resolveHttpCode()`), in order:

1. An exception implementing [`HttpStatusAware`](#http-status-aware-interface) whose `httpStatus()` is in 400–599 uses that status.
2. `SecurityException` uses its `getCode()` when it is 401, 403 or 429; anything else becomes 403.
3. `ValidationException`, `ServiceException` and the kernel `DomainException` → 422; `OptimisticLockException` and `LockTimeoutException` → 409; `GatewayException` → 502.
4. Everything else → 500, including any exception thrown during a request that is not listed above.

Severity comes from `ErrorClassifier::severityFor()`: `DomainException` and `ValidationException` are info; `OptimisticLockException`, `LockTimeoutException`, `SecurityException` and `ServiceException` are warning; everything else, including unknown exceptions, is critical. The boot-time exceptions never reach the HTTP error pipeline, because the application never starts serving.

::: info Upgrading from 1.17 or earlier
Before this release the kernel `DomainException` and both lock exceptions answered **500**, and the lock exceptions were **critical**. If a client branches on those codes, or an alert rule pages on them, set `ERROR_STATUS_LEGACY=true` to keep the old mapping (status and severity together) while you migrate. The flag will be removed in 2.0.
:::

::: tip Note on PHP's built-in `\DomainException`
Only the kernel's `AlfacodeTeam\...\Exceptions\DomainException` maps to 422. PHP's built-in `\DomainException`, which domain entities throw, is an unknown exception to the pipeline: 500, critical. Let the service catch it and rethrow a `ServiceException` (422), as the [service pattern](/layers/service) does.
:::

## Security Exception

Thrown when identity is denied, permissions are missing, or authentication fails.

```php
class SecurityException extends FrameworkException {}
```

**Maps to HTTP status:**
- `401` (Unauthorized) — identity is missing or invalid
- `403` (Forbidden) — identity is valid but lacks permission
- `429` (Too Many Requests) — rate limit exceeded

Pass the status as `code:`; any other code (including the default `0`) is answered as 403.

```php
throw new SecurityException(
    'Invalid API key.',
    layer: 'security.api_key',
    code: 401,
);

throw new SecurityException(
    'You do not have permission to delete invoices.',
    layer: 'security.authorization',
    code: 403,
    context: ['required_permission' => 'invoice:delete'],
);
```

## Validation Exception

Thrown when input data is invalid. The `$errors` property maps field names to error messages.

```php
class ValidationException extends FrameworkException
{
    /** @param array<string, string|string[]> $errors */
    public function __construct(
        public readonly array $errors,
        string $message = 'The request data is invalid.',
    ) {
        parent::__construct($message, layer: 'validation');
    }
}
```

**Usage:**

```php
throw new ValidationException(
    errors: [
        'email'    => 'Invalid email address.',
        'password' => 'Must be at least 8 characters.',
        'terms'    => ['You must accept the terms.', 'You must accept the privacy policy.'],
    ],
    message: 'Validation failed.',
);
```

**Response shape:**

```json
{
  "error": {
    "code": "validation_failed",
    "message": "The request data is invalid.",
    "fields": {
      "email": "Invalid email address.",
      "password": "Must be at least 8 characters."
    }
  }
}
```

## Domain Exception

Thrown by the domain layer when a business rule is violated. This is an **expected outcome**, not a system failure.

```php
class DomainException extends FrameworkException {}
```

**Note:** Inherits from `FrameworkException`, but domain code actually throws PHP's built-in `\DomainException`. The framework's `DomainException` is for cases where domain logic runs at the service or gateway layer.

```php
// In domain layer — use PHP's built-in
throw new \DomainException('Cannot issue a draft invoice.');

// In service layer — when translating domain failures
try {
    $invoice->issue();
} catch (\DomainException $e) {
    throw new ServiceException(
        'Invoice could not be issued.',
        layer: 'service.invoice.issue',
        previous: $e,
    );
}
```

## Service Exception

Thrown by services when business logic fails. Maps to HTTP 422 (client error) by default, but can carry any 4xx/5xx status via `$code`.

```php
class ServiceException extends FrameworkException {}
```

```php
throw new ServiceException(
    'Failed to create invoice: insufficient funds.',
    layer:   'service.invoice.create',
    code:    422,
    context: ['clientId' => '…', 'available' => 5000, 'required' => 10000],
);

throw new ServiceException(
    'External email service temporarily unavailable.',
    layer:   'service.notification.send',
    code:    500,  // Service failure, not client error
    previous: $gatewayException,
);
```

## Repository Exception

Thrown when a database operation fails. Always maps to HTTP 500.

```php
class RepositoryException extends FrameworkException {}
```

Repositories catch `\PDOException` and re-throw as `RepositoryException`:

```php
try {
    $result = $this->db->query($sql, $params);
} catch (\PDOException $e) {
    throw new RepositoryException(
        'Failed to fetch invoices.',
        layer:    'repository.invoice.list',
        context:  ['tenant' => $tenantId],
        previous: $e,
    );
}
```

## Gateway Exception

Thrown when a vendor API call or external integration fails. Always maps to HTTP 502 (Bad Gateway).

```php
class GatewayException extends FrameworkException {}
```

Gateways catch **every vendor exception** and re-throw as `GatewayException`:

```php
try {
    $response = $this->stripe->charges->create([...]);
} catch (\Stripe\Exception\CardException $e) {
    throw new GatewayException(
        'Card declined: ' . $e->getMessage(),
        layer:    'gateway.stripe.charge',
        context:  ['decline_code' => $e->getDeclineCode()],
        previous: $e,
    );
} catch (\Stripe\Exception\RateLimitException $e) {
    throw new GatewayException(
        'Stripe rate limit exceeded.',
        layer: 'gateway.stripe.charge',
        code: 429,
        previous: $e,
    );
} catch (\Throwable $e) {
    throw new GatewayException(
        'Unexpected Stripe error.',
        layer: 'gateway.stripe.charge',
        previous: $e,
    );
}
```

## Kernel Exception

Thrown by the kernel itself (HTTP pipeline, DI container, routing) when something goes wrong that prevents the request from being handled. Always maps to HTTP 500.

```php
class KernelException extends FrameworkException {}
```

Users rarely throw this; the kernel throws it when it detects misconfiguration or internal failures.

## Optimistic Lock Exception

Thrown when an optimistic locking conflict occurs—a domain object's version has changed since it was loaded, so the update is rejected.

```php
class OptimisticLockException extends RepositoryException {}
```

It extends `RepositoryException`, but the pipeline checks it first: a lost race is a normal concurrency outcome, so it answers **409 Conflict** with severity **warning**. Outside debug mode the response says "This record was changed by someone else. Reload and try again." rather than the exception's own message; the original goes to the log. Catch it in the service to retry, or let it reach the client, which should reload and try again.

```php
try {
    $this->db->execute(
        "UPDATE invoices SET status = :status, version = version + 1 
         WHERE id = :id AND version = :version",
        ['id' => $id, 'version' => $currentVersion, 'status' => 'issued'],
    );

    // Check if the update matched any row
    // (if not, the version was stale)
} catch (\PDOException $e) {
    throw new RepositoryException('Update failed.', layer: '...', previous: $e);
}
```



## Lock Timeout Exception

Thrown when a distributed lock could not be acquired within the allowed wait time. This is **contention, not corruption** — the usual response is to retry or skip the work.

```php
final class LockTimeoutException extends FrameworkException
{
    public static function for(string $name, int $seconds): self
    {
        return new self(
            "Timed out after {$seconds}s waiting for lock [{$name}].",
            layer: 'cache.lock',
            context: ['lock' => $name, 'waited' => $seconds],
        );
    }
}
```

```php
try {
    $lock = $this->cache->lock('invoice:' . $id, seconds: 30);
    $lock->block(10, fn () => $this->doWork($id));  // waits up to 10s, else LockTimeoutException
} catch (LockTimeoutException $e) {
    // Another process is updating this invoice; retry later
}
```

If it escapes during a request it answers **409 Conflict** with severity **warning**: contention, not a fault. Outside debug mode the response says "The resource is busy. Try again shortly.", because the exception's own message names the internal lock key. Usually you catch it where you take the lock and skip or retry the work.

## Scope Violation Exception

Thrown by the DI container when code tries to resolve a binding outside of its allowed scope.

```php
class ScopeViolationException extends \RuntimeException {}
```

This is a **programming error**, not a runtime condition. It indicates a module is trying to use another module's internal binding, which violates layering.

```php
// In InvoiceModule's Provider
$container->bindInternal(InvoiceRepository::class, fn() => ...);

// Somewhere else, in a different module
$repo = $container->make(InvoiceRepository::class);  // ScopeViolationException!
```

It is thrown at resolution time, usually inside a request, so the error pipeline reports it like any unknown exception: HTTP 500, severity critical.

## Circular Dependency Exception

Thrown during boot when the dependency graph contains a cycle — module A requires B, B requires C, C requires A.

```php
class CircularDependencyException extends \RuntimeException {}
```

This is a **configuration error**, caught at boot time. The application cannot start.

```php
// Not HTTP-relevant; application will not boot
```

## Boot Exception and Boot Failure Exception

`BootException` is thrown by individual boot stages when validation fails. The `BootPipeline` catches it and re-wraps it as `BootFailureException` with the stage name attached.

```php
// src/Kernel/Boot/BootException.php
class BootException extends \RuntimeException {}

// src/Kernel/Exceptions/BootFailureException.php
class BootFailureException extends \RuntimeException {}
```

**Not HTTP-relevant; application fails to start.**

## Entry Not Found Exception

Thrown by the DI container when `make()` is called with an ID that is not bound.

```php
final class EntryNotFoundException extends \RuntimeException 
    implements NotFoundExceptionInterface, ContainerExceptionInterface {}
```

Implements PSR-11 for standard container interop. Thrown during a request, it is answered as 500 and classified critical.

## Rejected Job Exception

Thrown by the worker pipeline when a dequeued job fails signature verification (the message cannot be cryptographically proven to be from the application).

```php
final class RejectedJobException extends SecurityException
{
    public function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, layer: 'worker.signature', code: 403, previous: $previous);
    }
}
```

Extends `SecurityException` so it gets the same logging/alerting treatment. It is classified as **warning** severity (a security incident, but not a system fault).

## Http Status Aware Interface

Custom exceptions can implement `HttpStatusAware` to declare their own HTTP status. The error pipeline consults this interface FIRST, before built-in mappings.

```php
<?php declare(strict_types=1);
namespace AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions;

interface HttpStatusAware
{
    /** The HTTP status for this exception. Must be 4xx or 5xx. */
    public function httpStatus(): int;
}
```

**Example: project-defined conflict exception**

```php
final class SeatTakenException extends \RuntimeException implements HttpStatusAware
{
    public function httpStatus(): int { return 409; }
}

throw new SeatTakenException('Seat is already reserved.');
// ErrorStage returns HTTP 409, not 500
```

## Throw Guidelines

| Situation | Exception | Where |
|-----------|-----------|-------|
| Bad input | `ValidationException` | Controller, request validation |
| Deny access | `SecurityException` | Security layers, middleware |
| Business rule broken | `\DomainException` (built-in) | Domain layer |
| Domain logic failed | `ServiceException` | Service layer |
| DB operation failed | `RepositoryException` | Repository layer |
| Vendor call failed | `GatewayException` | Gateway layer |
| Update conflict (version) | `OptimisticLockException` | Repository layer |
| Cannot acquire lock | `LockTimeoutException` | Cache/lock layer |

## Error Response Envelope

All error responses follow this shape:

```json
{
  "error": {
    "code": "error_identifier",
    "message": "Human-readable message.",
    "requestId": "correlation-id-uuid",
    "fields": {}
  }
}
```

- `code` — machine-readable identifier (layer name, e.g., `'service.invoice.create'`)
- `message` — human-readable explanation
- `requestId` — correlation ID for tracing
- `fields` — present only on 422 (ValidationException)

## Common Mistakes

### ✗ Throwing Vendor Exceptions

```php
// WRONG — caller must know Stripe
catch (\Stripe\Exception\CardException $e) {
    throw $e;  // Escapes to caller
}
```

### ✓ Translate to GatewayException

```php
// RIGHT — uniform exception type
catch (\Stripe\Exception\CardException $e) {
    throw new GatewayException(
        'Card declined.',
        layer: 'gateway.stripe.charge',
        previous: $e,
    );
}
```

### ✗ Swallowing Exceptions

```php
// WRONG — no one knows the error happened
try {
    $invoice->issue();
} catch (\DomainException $e) {
    // silently ignore
}
```

### ✓ Translate and Re-throw

```php
// RIGHT — log and propagate
try {
    $invoice->issue();
} catch (\DomainException $e) {
    throw new ServiceException(
        'Invoice could not be issued.',
        previous: $e,
    );
}
```

## Source

- [src/Kernel/Exceptions/](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Exceptions/)
- [src/Kernel/Boot/BootException.php](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Boot/BootException.php)
- [src/Kernel/Container/EntryNotFoundException.php](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Container/EntryNotFoundException.php)
- [src/Kernel/Pipelines/Worker/RejectedJobException.php](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Pipelines/Worker/RejectedJobException.php)
