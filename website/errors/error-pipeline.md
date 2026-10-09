# Error Pipeline

The error pipeline catches exceptions, classifies them by severity, and routes them to the appropriate notifiers (Slack, email, database, file logs). The pipeline never throws. You can configure a fallback notifier (typically FileNotifier) to ensure errors are recorded even if other notifiers fail; you can also add email alerts, database logging, and Slack notifications as needed.

## How It Works

1. **ErrorStage** catches any `Throwable` from the request pipeline
2. **ErrorContext** captures the exception, identity, correlation ID, and stack trace
3. **ErrorPipeline** routes the error to notifiers based on its severity
4. **Notifiers** process the error (post to Slack, send email, write to database)
5. **Fallback notifier** runs as a final catch-all (typically FileNotifier) if configured

::: tip If you configure nothing
Without a `withErrorPipeline()` call, the kernel uses `ErrorPipeline::default()`: a single `FileNotifier` writing every severity to `var/logs/errors.log`, rotated daily. Once you pass your own pipeline, **that default is gone**. Errors are logged to a file only if you add `->fallback(new FileNotifier(...))` or list `file` in `rules()`.
:::

```
Request
  ↓
[Pipeline Stages]
  ↓
Exception Thrown ←─ ErrorStage catches
  ↓
ErrorContext captures exception details
  ↓
ErrorPipeline::consume()
  ├─ Route to notifiers by severity
  ├─ Run each notifier (isolated failures)
  └─ Guaranteed fallback (FileNotifier)
  ↓
HTTP 4xx/5xx response returned
```

## Configuring the Error Pipeline

Configure the pipeline in your bootstrap (`bootstrap/app.php`):

```php
<?php
use AlfacodeTeam\PhpServicePlatform\Kernel\Error\ErrorPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Error\Notifiers\{
    SlackNotifier, MailNotifier, DatabaseErrorLogger, FileNotifier
};

$kernel = $builder
    ->withErrorPipeline(
        ErrorPipeline::notifiers([
            new SlackNotifier(env('SLACK_ERROR_WEBHOOK')),
            new MailNotifier($mailPort, config('errors.mail_to'), view: 'errors.alert'),
            new DatabaseErrorLogger($databasePort, table: 'error_logs'),
        ])
        ->fallback(new FileNotifier(logs_path('errors.log'), rotateDaily: true))
        ->rules([
            'critical' => ['slack', 'mail', 'database', 'file'],
            'warning'  => ['database', 'file'],
            'info'     => ['file'],
        ])
    )
    ->build();
```

## ErrorPipeline API

```php
// Create a pipeline with a list of notifiers
public static function notifiers(NotifierContract[] $notifiers): self

// Set the guaranteed fallback notifier (always runs)
public function fallback(NotifierContract $notifier): self

// Define which notifiers run for each severity
public function rules(array<string, string[]> $rules): self

// Consume an error (run notifiers) — never throws
public function consume(ErrorContext $context): void
```

## ErrorContext

`ErrorContext` is an immutable snapshot of the exception at the moment it was caught:

```php
final readonly class ErrorContext
{
    public function __construct(
        public string  $exceptionClass,         // e.g. 'App\Payment\ServiceException'
        public string  $message,
        public string  $severity,               // 'critical', 'warning', 'info'
        public string  $layer,                  // e.g. 'service.invoice.create'
        public array   $context,                // typed data from exception->context
        public ?string $correlationId,
        public ?string $requestPath,            // e.g. '/api/invoices'
        public ?string $requestMethod,          // 'POST'
        public ?string $userId,
        public array   $trace,                  // compact 20-frame stack
        public ?string $previousClass,          // chain: what caused the exception
        public string  $occurredAt,             // RFC3339 timestamp
    ) {}

    /**
     * Create from a caught exception.
     */
    public static function fromThrowable(
        \Throwable $e,
        ?string $correlationId = null,
        ?string $requestPath = null,
        ?string $requestMethod = null,
        ?string $userId = null,
    ): self

    /**
     * Export as an associative array for logging.
     */
    public function toArray(): array
}
```

## Error Classifier

The classifier maps exceptions to severity levels: **critical** (page someone), **warning** (log + dashboard), **info** (log only).

| Exception | Severity | Why |
|-----------|----------|-----|
| `DomainException` | info | Business rule violated; expected |
| `ValidationException` | info | Input invalid; client-correctable |
| `SecurityException` | warning | Security incident; watch but not urgent |
| `ServiceException` | warning | Business logic failed; may need review |
| `RepositoryException` | critical | Database failed; infrastructure problem |
| `GatewayException` | critical | Vendor API failed; external dependency problem |
| `KernelException` | critical | Framework failed; application broken |
| Everything else | critical | Unknown error; assume severe |

```php
final class ErrorClassifier
{
    public const CRITICAL = 'critical';
    public const WARNING  = 'warning';
    public const INFO     = 'info';

    public static function severityFor(\Throwable $e): string
    {
        return match (true) {
            $e instanceof DomainException,
            $e instanceof ValidationException     => self::INFO,

            $e instanceof SecurityException,
            $e instanceof ServiceException        => self::WARNING,

            $e instanceof RepositoryException,
            $e instanceof GatewayException,
            $e instanceof KernelException         => self::CRITICAL,

            default                               => self::CRITICAL,
        };
    }
}
```

## Notifiers

A notifier is any class implementing `NotifierContract`. The kernel ships four; you can add more.

### NotifierContract

```php
interface NotifierContract
{
    /** Stable identifier used in rules(), e.g. 'slack', 'mail', 'file' */
    public function name(): string;

    /**
     * Process the error. MUST NOT throw.
     * If delivery fails, swallow it; the fallback notifier will catch it.
     */
    public function notify(ErrorContext $context): void;
}
```

### SlackNotifier

Posts a compact alert to a Slack incoming webhook:

```php
new SlackNotifier(
    webhookUrl: env('SLACK_ERROR_WEBHOOK'),
    timeoutSeconds: 3,  // default
)
```

The webhook URL must be a valid Slack incoming webhook URL (e.g., `https://hooks.slack.com/services/T1234/B5678/abcdef`). If the URL is empty, the notifier silently skips.

**Output:**

```
[CRITICAL] App\Payment\ServiceException
Payment processing failed.
layer: service.payment.charge | request: POST /api/checkout | id: 2025-09-14-abc123
```

### MailNotifier

Sends an email to on-call recipients via `MailPort`:

```php
new MailNotifier(
    mail: $mailPort,
    recipients: ['oncall@example.com'],  // string or string[]
    view: 'errors.alert',  // view name for the email template
)
```

The notifier calls `$mailPort->send()` with the error context as the view data. The view template can access `$exceptionClass`, `$message`, `$severity`, `$layer`, `$requestPath`, etc.

### DatabaseErrorLogger

Writes errors to an `error_logs` table:

```php
new DatabaseErrorLogger(
    db: $databasePort,
    table: 'error_logs',  // default
)
```

**Expected schema:**

```sql
CREATE TABLE error_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    correlation_id VARCHAR(36),
    severity VARCHAR(20),
    exception_class VARCHAR(255),
    message TEXT,
    layer VARCHAR(255),
    context JSON,
    request_method VARCHAR(10),
    request_path VARCHAR(255),
    user_id VARCHAR(36),
    occurred_at DATETIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_severity (severity),
    INDEX idx_correlation (correlation_id),
    INDEX idx_user (user_id),
    INDEX idx_occurred (occurred_at)
);
```

### FileNotifier

The default fallback notifier. Appends one JSON line per error to a log file:

```php
new FileNotifier(
    path: logs_path('errors.log'),
    rotateDaily: true,  // optional
)
```

Set it as the fallback with `->fallback(new FileNotifier(...))` to ensure errors are recorded even if other notifiers fail.

**Rotation:**

When enabled, the date is injected before the file extension so logs stay bounded:

- `errors.log` → `errors-2025-09-14.log` (daily file per date)
- Old files can be archived or pruned independently
- Rotation is computed per-write, so it is safe under long-lived Swoole workers

**Output (JSON, one per line):**

```json
{
  "exception": "App\\Payment\\ServiceException",
  "message": "Payment processing failed.",
  "severity": "critical",
  "layer": "service.payment.charge",
  "context": {"clientId": "c-123", "amount": 9999},
  "correlation_id": "2025-09-14-abc123",
  "request": {"method": "POST", "path": "/api/checkout", "user": "u-456"},
  "occurred_at": "2025-09-14T14:32:10+00:00",
  "trace": [
    {"file": "src/Payment/Service.php", "line": 42, "function": "Charge::handle()"},
    {"file": "src/Http/Middleware/Pipeline.php", "line": 18, "function": "pipeline()"}
  ]
}
```

## Debug Mode

When `APP_DEBUG=true`, the error response includes a rich HTML debug page for browser requests (non-API, non-JSON responses).

**Browser Requests:**

The debug page shows:

- Exception class and message
- Source code snippet around the failing line
- Full stack trace with expandable frames
- File previews for each frame
- Environment and request info cards

**API Requests:**

API requests (`Accept: */json`, `/api` paths, AJAX) always receive JSON, not the HTML debug page. With `APP_DEBUG=true`, the JSON response includes the actual error message. With `APP_DEBUG=false`, the message is masked as "An internal error occurred."

**Security:** The debug page exposes source code and paths. It is ONLY rendered for browser requests when `APP_DEBUG=1`, `on`, `yes`, or `true`, and NEVER in production.

::: warning Never Enable DEBUG in Production
Set `APP_DEBUG=false` in your production `.env`. Debug pages expose sensitive information.
:::

## Worker and CLI Error Reporting

Workers and CLI commands run outside the HTTP pipeline, so they have their own error handling:

- **Worker:** The `WorkerLoop` catches exceptions from job handlers and routes them through `ErrorPipeline::consume()` before acknowledging or re-queueing the message
- **CLI:** The `CliPipeline` catches exceptions and routes them through `ErrorPipeline::consume()` before exiting with a non-zero exit code

Both pipelines use the same error pipeline and notifiers configured at boot.

## Common Mistakes

### ✗ Throwing from a Notifier

```php
// WRONG — if SlackNotifier throws, other notifiers don't run
public function notify(ErrorContext $context): void
{
    curl_exec(...);  // Could throw or return false
}
```

### ✓ Never Throw from Notifiers

```php
// RIGHT — swallow all errors
public function notify(ErrorContext $context): void
{
    try {
        curl_exec(...);
    } catch (\Throwable) {
        // Swallow — the fallback notifier still logs the error
    }
}
```

### ✗ Misconfigured Rules

```php
// WRONG — defines a rule for notifier 'slack' but the notifier's name() is 'slack_notifications'
->rules([
    'critical' => ['slack'],  // Will never run
])
```

### ✓ Match Rules to Notifier Names

```php
// RIGHT — use the notifier's name()
new class implements NotifierContract {
    public function name(): string { return 'my_notifier'; }
};

->rules([
    'critical' => ['my_notifier'],  // Correct
])
```

### ✗ Omitting the Fallback

```php
// WRONG — if all configured notifiers fail, nothing is logged
ErrorPipeline::notifiers([...])->rules([...]);  // No fallback!
```

### ✓ Always Configure a Fallback

```php
// RIGHT — FileNotifier is the guaranteed fallback
ErrorPipeline::notifiers([...])
    ->fallback(new FileNotifier(logs_path('errors.log')))
    ->rules([...]);
```

## Source

- [src/Kernel/Error/ErrorPipeline.php](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Error/ErrorPipeline.php)
- [src/Kernel/Error/ErrorContext.php](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Error/ErrorContext.php)
- [src/Kernel/Error/ErrorClassifier.php](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Error/ErrorClassifier.php)
- [src/Kernel/Error/Contracts/NotifierContract.php](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Error/Contracts/NotifierContract.php)
- [src/Kernel/Error/Notifiers/](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Error/Notifiers/)
- [src/Kernel/Pipelines/Http/Stages/ErrorStage.php](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Pipelines/Http/Stages/ErrorStage.php)
