# HKM Kernel — Error Handling

> Every exception has a layer, a severity, and a chain of notifiers.
> Errors flow through the ErrorPipeline automatically — module code only throws,
> never handles infrastructure-level error reporting.

---

## Exception Hierarchy and Mapping

```
FrameworkException (base — always use a subclass)
├── SecurityException   → HTTP 401 / 403 / 429  → severity: warning
├── DomainException     → HTTP 422               → severity: info
├── ServiceException    → HTTP 422 / 500         → severity: warning
├── RepositoryException → HTTP 500               → severity: critical
├── GatewayException    → HTTP 502               → severity: critical
└── KernelException     → HTTP 500               → severity: critical
```

**Rule: Throw the exception type matching the layer where the error originates.**

---

## Throwing the Right Exception

```php
// ── Domain layer ─────────────────────────────────────────────────────────
// Use built-in \DomainException (not a HKM Kernel type)
throw new \DomainException('Invoice must have at least one line item before issuing');

// ── Service layer ─────────────────────────────────────────────────────────
throw new ServiceException(
    message:  'invoice.create.failed',     // dot-notation error code
    layer:    'service.invoice',
    context:  ['clientId' => $dto->clientId, 'userId' => $this->identity->userId],
    previous: $e,                          // always chain the original exception
);

// ── Repository layer ──────────────────────────────────────────────────────
throw new RepositoryException(
    message:  "Invoice [{$id}] not found",
    layer:    'repository.invoice',
    context:  ['invoiceId' => $id],
    previous: $e,
);

// ── Gateway layer ─────────────────────────────────────────────────────────
throw new GatewayException(
    message:  'Stripe card declined: ' . $e->getError()->message,
    layer:    'gateway.stripe.charge',
    context:  ['decline_code' => $e->getError()->decline_code],
    previous: $e,
);

// ── Security layer ────────────────────────────────────────────────────────
// DON'T throw — return SecurityVerdict::deny()
return SecurityVerdict::deny(401, 'Invalid JWT signature');
```

---

## FrameworkException Constructor

```php
abstract class FrameworkException extends \RuntimeException
{
    public function __construct(
        string      $message,
        public readonly string    $layer    = '',      // 'service.invoice', 'gateway.stripe'
        public readonly array     $context  = [],      // additional typed context
        int         $code     = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
```

---

## Error Context — What Gets Captured Automatically

The `ErrorPipeline` captures all of this on every error — no code needed in modules:

| Field | Source | Example |
|---|---|---|
| `id` | Generated | `err_01H9X2K3M4` |
| `severity` | `ErrorClassifier` | `critical` |
| `layer` | Exception `.layer` | `gateway.stripe.charge` |
| `message` | Exception message | `Stripe card declined` |
| `trace` | PHP stack trace | Full trace |
| `context` | Exception `.context` | `{decline_code: 'insufficient_funds'}` |
| `requestId` | `CorrelationIdStage` | `20250615-a3f8b2c1` |
| `requestPath` | HTTP Request | `/api/payments` |
| `requestMethod` | HTTP Request | `POST` |
| `userId` | Identity | `usr_01H9X1A2B3` |
| `tenantId` | Identity | `ten_01H9X1A2B4` |
| `previous` | Exception chain | Previous exception message |
| `occurredAt` | Timestamp | `2025-06-15T14:23:01.234Z` |
| `environment` | `APP_ENV` | `production` |

---

## Error Severity Rules

```php
// config/errors.php
return [
    'severity_rules' => [
        'critical' => ['slack', 'mail', 'database', 'file'],
        'warning'  => ['database', 'file'],
        'info'     => ['file'],
    ],
];

// Exception type → default severity mapping:
// SecurityException   → warning  (but configurable per-route)
// DomainException     → info     (business rule violation — expected)
// ServiceException    → warning  (service-level failure)
// RepositoryException → critical (database problem — ops team alerted)
// GatewayException    → critical (third-party down — ops team alerted)
// Unknown Throwable   → critical (unexpected — highest priority)
```

---

## HTTP Error Response Format

```json
// 4xx / 5xx response body — always this shape
{
  "error": {
    "code":      "invoice.not_found",
    "message":   "Invoice [inv_123] was not found.",
    "requestId": "20250615-a3f8b2c1",
    "fields":    {}  // present only on 422 validation errors
  }
}
```

```json
// 422 Validation error — fields map
{
  "error": {
    "code":      "validation.failed",
    "message":   "The request data is invalid.",
    "requestId": "20250615-b4c9d3e2",
    "fields": {
      "dueDate":               ["Due date must be in the future."],
      "lineItems":             ["At least one line item is required."],
      "lineItems.0.unitPrice": ["Unit price must be greater than zero."]
    }
  }
}
```

---

## Two Error Layers (nested nets)

The `ErrorPipeline` only handles errors *inside* a built kernel. A second, outer
layer catches what the kernel cannot — failures before it exists, and PHP fatals.

```
ErrorGuard (App\Bootstrap\Environment, SAPI-level)  ── outer net
   catches: pre-kernel throws (e.g. base.php APP_KEY guard), parse errors, fatals, OOM
   └── ErrorStage / ErrorPipeline (kernel)           ── inner net
          catches: Throwables inside a running HTTP/CLI/worker pipeline
```

| Aspect | ErrorGuard | ErrorStage / ErrorPipeline |
|---|---|---|
| Layer | Project bootstrap (`app/Bootstrap/Environment/ErrorGuard.php`) | Kernel |
| Alive from | first line of the entry point — before the kernel | only after `materialize()` |
| Catches | pre-kernel throws, fatals/parse/OOM (uncatchable by try/catch) | Throwables in a running pipeline |
| Capability | generic 500 + log; debug page in debug | classify → notifiers (Slack/Mail/DB/File) |

**Shared log sink:** both write to `{project}/var/logs/errors.log` — the kernel via
`FileNotifier`, ErrorGuard by appending a compatible JSON line tagged
`source=error_guard`. ErrorGuard never calls the ErrorPipeline (no global singletons);
the connection is the shared file only.

**Install order (every entry point):**

```php
LoadEnvironment::load($rootPath, $domain, $argv);
ErrorGuard::install($logRoot . '/var/logs/errors.log');   // ini-only on OpenSwoole
$kernel = require ...bootstrap;
```

ErrorGuard forces `display_errors=off` in production, so a pre-kernel crash can never
paint a stack trace to the browser.

---

## Developer Debug Page (debug mode only)

`src/Kernel/Error/DebugPageRenderer` is a dependency-free renderer (rich HTML page with
source preview + expandable trace, plus an ANSI CLI trace). It lives in the kernel so both
error layers can reuse it (kernel may not depend on the project layer, but the project layer
may depend on the kernel).

- **Gated behind `APP_DEBUG=true`** — it exposes source code and stack traces, so it NEVER
  renders in production.
- **Browser only.** API / AJAX / JSON callers always get the JSON error body. The decision:
  - kernel ErrorStage: `Request::expectsJson()` (Accept `*/json`, `X-Requested-With`, JSON
    body) OR a `/api` path prefix → JSON.
  - ErrorGuard (pre-kernel, no Request): the same signals read from `$_SERVER` + `/api` prefix.
- Branded "HKM" (the debug page and CLI exception header).

---

## Standard Error Codes (Dot-Notation)

```
Format: {module}.{resource}.{condition}

Auth errors:
  auth.token.missing         → 401  No Authorization header
  auth.token.invalid         → 401  Bad signature or format
  auth.token.expired         → 401  Past exp claim
  auth.credentials.invalid   → 401  Wrong email or password (same message for both)
  auth.refresh.reuse_detected→ 401  Refresh token reuse — all sessions invalidated

Authorization errors:
  authz.permission.denied    → 403  Missing RBAC permission
  authz.ownership.denied     → 403  Not the owner of this resource

Resource errors:
  invoice.not_found          → 404
  invoice.already_paid       → 409  Duplicate payment attempt
  invoice.status.invalid     → 422  Invalid state machine transition
  invoice.create.unauthorized→ 403

Validation errors:
  validation.failed          → 422  General validation failure (fields populated)
  validation.required        → 422  Required field missing
  validation.invalid_format  → 422  Field format incorrect

System errors:
  gateway.timeout            → 504  Third-party did not respond
  gateway.unavailable        → 502  Third-party is down
  system.maintenance         → 503  Maintenance mode active
```

---

## Exception Translation Chain

```
\PDOException (thrown by PDO)
    │
    ▼  caught by Repository
RepositoryException (layer: 'repository.invoice')
    │
    ▼  propagates to Service (not caught — let it bubble)
ServiceException wrapper (optional — add context if needed)
    │
    ▼  propagates to ErrorStage
ErrorPipeline.normalize()  → adds requestId, userId, etc.
ErrorPipeline.classify()   → assigns severity
ErrorPipeline.dispatch()   → notifies Slack/Mail/DB/File
    │
    ▼
HTTP Response: 500 {"error": {"code": "repository.error", "requestId": "..."}}
```

---

## What Module Code Must NOT Do With Errors

```php
// WRONG: catching errors to silently ignore them
try {
    $this->repository->save($invoice);
} catch (RepositoryException $e) {
    // logging manually and swallowing — NEVER do this
    error_log($e->getMessage());
    // the error goes unreported to the ErrorPipeline
}

// WRONG: catching generic Throwable in the service
try {
    $result = $this->gateway->charge($dto);
} catch (\Throwable $e) {
    return ChargeResult::failed('unknown error'); // hides the real problem
}

// CORRECT: let exceptions propagate to the ErrorStage
// Only catch what you can meaningfully handle and rethrow wrapped
try {
    $this->gateway->charge($dto);
} catch (GatewayException $e) {
    // Rethrow as ServiceException to add service-level context
    throw new ServiceException('payment.charge.failed', layer: 'service.payment', previous: $e);
}
```

---

## Rules for Error Handling Code

When writing or reviewing error handling code:

- **DO** throw the exception type matching the layer (`RepositoryException` in repos, etc.)
- **DO** include `layer:` in format `'layer.sublayer'` — e.g. `'repository.invoice'`
- **DO** include `context:` with relevant IDs and values for debugging
- **DO** chain the original exception with `previous: $e` — never lose the original
- **DO** use dot-notation error codes — `'invoice.create.failed'` not `'error'`
- **DON'T** catch exceptions in the service to silently swallow them
- **DON'T** use `error_log()`, `var_dump()`, or `print_r()` — the ErrorPipeline handles logging
- **DON'T** throw generic `\Exception` or `\RuntimeException` — always a HKM Kernel subclass
- **DON'T** put notification logic in modules — it belongs in the ErrorPipeline notifiers
- **DON'T** expose internal details (SQL, stack traces, vendor messages) in HTTP responses
