# Ports — Dependency contracts

Ports define the interfaces through which modules interact with infrastructure. The kernel defines port contracts; projects and plugins provide implementations bound via `withPorts()` into the core container. This separation keeps business logic free of vendor coupling and makes code testable against fakes.

## Port principles

A port implementation binds once at boot into the `CoreContainer` and is shared across every request and job. This works safely only for **stateless infrastructure** — ports that hold connection pools, credentials, or configuration but never request-scoped data. For request-scoped state (session, cookies, tenant overrides), define module-level bindings in `Provider::register()` instead.

## DatabasePort

The exclusive path from repositories to the database. The kernel defines the contract; a plugin or project provides the adapter (e.g., a PDO wrapper).

```php
interface DatabasePort
{
    public function query(string $sql, array $params = []): array;
    public function queryOne(string $sql, array $params = []): ?array;
    public function execute(string $sql, array $params = []): int;
    public function upsert(string $table, array $values, array $conflictColumns, ?array $updateColumns = null): int;
    public function lastInsertId(?string $sequence = null): string;
    public function beginTransaction(): void;
    public function commit(): void;
    public function rollback(): void;
    public function inTransaction(): bool;
}
```

- `query($sql, $params)` — run a SELECT-like statement and return ALL matching rows as an array of associative arrays.
- `queryOne($sql, $params)` — run a SELECT-like statement and return the first row or null.
- `execute($sql, $params)` — run a non-SELECT statement (INSERT, UPDATE, DELETE) and return the number of affected rows.
- `upsert($table, $values, $conflictColumns, $updateColumns)` — atomic INSERT-or-UPDATE compiled to the underlying driver's SQL (MySQL's `ON DUPLICATE KEY`, PostgreSQL's `ON CONFLICT`, etc.). Repositories must never hand-write dialect-specific syntax. `$updateColumns` defaults to all non-conflict columns; pass `[]` for insert-if-absent.
- `lastInsertId($sequence)` — the auto-increment id of the last inserted row. On PostgreSQL, pass the sequence name; MySQL and SQLite ignore it.
- `beginTransaction()` — start a transaction.
- `commit()` — commit and close.
- `rollback()` — rollback and close.
- `inTransaction()` — whether a transaction is currently active.

### DriverAware (optional capability)

A `DatabasePort` may also implement `DriverAware` to name the SQL dialect it speaks:

```php
interface DriverAware
{
    public function driver(): string;  // 'mysql' | 'pgsql' | 'sqlite' | 'sqlsrv'
}
```

Check `instanceof DriverAware` and fall back to configuration (the `DB_DRIVER` env var) when the bound port does not implement this. A repository that hard-required it would be unusable against in-memory test fakes.

## CachePort

Caching and distributed locking through a unified interface.

```php
interface CachePort
{
    public function get(string $key): mixed;
    public function set(string $key, mixed $value, ?int $ttl = null): bool;
    public function delete(string $key): bool;
    public function has(string $key): bool;
    public function remember(string $key, int $ttl, callable $callback): mixed;
    public function increment(string $key, int $by = 1): int;
    public function deletePattern(string $pattern): int;
    public function flush(): bool;
    public function lock(string $name, int $seconds = 0, ?string $owner = null): Lock;
    public function restoreLock(string $name, string $owner): Lock;
}
```

- `get($key)` — retrieve a value, or null when absent or expired.
- `set($key, $value, $ttl)` — store a value for $ttl seconds (null = no expiry). Returns true on success.
- `delete($key)` — remove a key. Returns true if it existed.
- `has($key)` — whether a key is set and not expired.
- `remember($key, $ttl, $callback)` — return the cached value, or run $callback and cache its result for $ttl seconds if the key is absent.
- `increment($key, $by)` — atomically increment a counter. Returns the new value. NOT a substitute for `lock()` — it has no ownership, no blocking, and no TTL-bounded release.
- `deletePattern($pattern)` — delete keys matching a shell-like pattern (e.g. `'session_*'`). Returns the count deleted. Not all adapters support this; check the adapter's documentation.
- `flush()` — clear the entire cache. Returns true on success.
- `lock($name, $seconds, $owner)` — obtain a mutually-exclusive, TTL-bounded lock. See `Lock` below.
- `restoreLock($name, $owner)` — reattach to a lock acquired elsewhere using its owner token, so THIS caller can release it.

## Lock

Distributed mutual exclusion with ownership verification. Obtained from `CachePort::lock()`.

```php
interface Lock
{
    public function acquire(): bool;
    public function block(int $seconds, ?callable $callback = null): mixed;
    public function release(): bool;
    public function owner(): string;
    public function forceRelease(): void;
}
```

- `acquire()` — try to acquire once without waiting. Returns true when this instance now holds the lock.
- `block($seconds, $callback)` — wait up to $seconds to acquire. With a callback, runs it while holding the lock, always releases afterwards (even if the callback throws), and returns the callback's return value. Without a callback, returns true once acquired; the caller then owns the release. Throws `LockTimeoutException` if the lock could not be acquired in time.
- `release()` — release the lock, but ONLY if this instance still owns it. Returns false when the lock had expired or is held by someone else.
- `owner()` — this instance's ownership token. Pass to `CachePort::restoreLock()` to reattach.
- `forceRelease()` — delete the lock regardless of owner. For administrative recovery only, never on the hot path.

### AbstractLock

Base class for Lock implementations. Implements `block()` with standard semantics; subclasses supply only `acquire()`, `release()`, and `forceRelease()`. Never reimplement `block()` in a subclass — timeout and callback semantics must be identical across all backends.

```php
abstract class AbstractLock implements Lock
{
    protected readonly string $name;
    protected readonly int $seconds;
    protected readonly string $owner;
    
    public function block(int $seconds, ?callable $callback = null): mixed { ... }
    protected static function randomOwner(): string { ... }
    protected function sleep(int $micros): void { ... }
}
```

The sleep method is coroutine-aware: under OpenSwoole/Swoole it yields the coroutine; otherwise it falls back to `usleep()`.

## QueuePort

The exclusive path for enqueueing and consuming background work.

```php
interface QueuePort
{
    public function push(string $jobClass, array $payload, string $queue = 'default', int $delay = 0): string;
    public function later(int $seconds, string $jobClass, array $payload, string $queue = 'default'): string;
    public function size(string $queue = 'default'): int;
    public function pop(string $queue = 'default'): ?JobPayload;
    public function ack(JobPayload $payload): void;
    public function release(JobPayload $payload, int $delay = 0): void;
    public function fail(JobPayload $payload, ?\Throwable $reason = null): void;
}
```

Write-side methods:

- `push($jobClass, $payload, $queue, $delay)` — enqueue a job immediately (or after $delay seconds). Returns the job id.
- `later($seconds, $jobClass, $payload, $queue)` — enqueue a job to run after $seconds. Returns the job id.
- `size($queue)` — count of ready + delayed jobs on the queue.

Read-side methods (used by the worker):

- `pop($queue)` — reserve the next due job, or null when idle. Must not block.
- `ack($payload)` — the job succeeded. Remove it permanently.
- `release($payload, $delay)` — the job failed but may be retried. Return it to the queue after $delay seconds, with its attempt count incremented.
- `fail($payload, $reason)` — the job exhausted its attempts. Move it to the dead-letter store for inspection, never silently drop it.

Every popped job must reach exactly one of ack/release/fail. An adapter that deletes on pop() loses jobs when a worker dies mid-handle; reserve-then-resolve is the only pattern that survives a crash.

## HttpClientPort

Outbound HTTP calls from gateways, keeping vendor coupling behind a port.

```php
interface HttpClientPort
{
    public function request(string $method, string $url, array $options = []): HttpClientResponse;
    public function get(string $url, array $query = []): HttpClientResponse;
    public function post(string $url, array $data = []): HttpClientResponse;
    public function put(string $url, array $data = []): HttpClientResponse;
    public function patch(string $url, array $data = []): HttpClientResponse;
    public function delete(string $url, array $data = []): HttpClientResponse;
    public function pending(): PendingRequestContract;
}
```

- `request($method, $url, $options)` — send an HTTP request with arbitrary options. $options keys: `headers`, `query`, `json`, `form`, `body`, `timeout`, `connect_timeout`, `retry`, `retry_methods`.
- `get($url, $query)` — send a GET with query parameters.
- `post($url, $data)`, `put()`, `patch()`, `delete()` — send the respective verb with form/JSON data.
- `pending()` — return a fluent `PendingRequestContract` builder for ergonomic call sites (auth headers, base URL, retries, multipart).

## PendingRequestContract

Immutable fluent builder for HTTP requests. Every method returns a new instance.

```php
interface PendingRequestContract
{
    public function baseUrl(string $url): static;
    public function withHeaders(array $headers): static;
    public function withHeader(string $name, string $value): static;
    public function withToken(string $token, string $type = 'Bearer'): static;
    public function withBasicAuth(string $username, string $password): static;
    public function asJson(): static;
    public function asForm(): static;
    public function asMultipart(): static;
    public function attach(string $name, string $contents, ?string $filename = null): static;
    public function acceptJson(): static;
    public function timeout(int $seconds): static;
    public function connectTimeout(int $seconds): static;
    public function retry(int $times): static;
    public function retryMethods(array $methods): static;
    public function get(string $url, array $query = []): HttpClientResponse;
    public function post(string $url, array $data = []): HttpClientResponse;
    public function put(string $url, array $data = []): HttpClientResponse;
    public function patch(string $url, array $data = []): HttpClientResponse;
    public function delete(string $url, array $data = []): HttpClientResponse;
    public function send(string $method, string $url, array $options = []): HttpClientResponse;
}
```

## HttpClientResponse

Immutable result of an outbound HTTP call.

```php
final class HttpClientResponse
{
    public function status(): int;
    public function body(): string;
    public function header(string $name): ?string;  // case-insensitive
    public function headers(): array;
    public function json(): mixed;  // decoded JSON body, or null
    public function ok(): bool;     // 2xx
    public function redirect(): bool;  // 3xx
    public function clientError(): bool;  // 4xx
    public function serverError(): bool;  // 5xx
    public function failed(): bool;  // 4xx or 5xx
    public function throw(string $layer = 'gateway.http_client'): self;  // throws GatewayException on fail
}
```

## StoragePort

Object storage (local filesystem, S3, etc.). The exclusive path for reading/writing blobs.

```php
interface StoragePort
{
    public function store(string $contents, string $filename, string $path = '', string $visibility = 'private'): string;
    public function storeStream($resource, string $filename, string $path = '', string $visibility = 'private'): string;
    public function get(string $path): string;
    public function readStream(string $path);
    public function temporaryUrl(string $path, int $expiresInSeconds = 3600): string;
    public function exists(string $path): bool;
    public function delete(string $path): bool;
}
```

- `store($contents, $filename, $path, $visibility)` — write a blob. Returns the stored path.
- `storeStream($resource, $filename, $path, $visibility)` — write from a stream without buffering. Returns the stored path.
- `get($path)` — read the full contents.
- `readStream($path)` — open a readable stream. Caller owns closing it.
- `temporaryUrl($path, $expiresInSeconds)` — a signed URL valid for the given duration. For S3 and similar.
- `exists($path)` — check if a blob exists.
- `delete($path)` — remove a blob.

### RangeReadableStorage (optional capability)

For serving large files with HTTP Range support, a `StoragePort` may also implement:

```php
interface RangeReadableStorage
{
    public function size(string $path): int;
    public function readRange(string $path, int $offset, ?int $length = null);
}
```

- `size($path)` — byte length without reading contents.
- `readRange($path, $offset, $length)` — open the bytes [$offset, $offset + $length). $length null means to EOF.

Check `instanceof RangeReadableStorage` and fall back to `readStream()` and full transfer when the port does not implement this.

## MailPort

Sending transactional mail.

```php
interface MailPort
{
    public function send(string|array $to, string $subject, string $view, array $data = []): void;
    public function queue(string|array $to, string $subject, string $view, array $data = []): string;
}
```

- `send($to, $subject, $view, $data)` — send immediately. $to is one or more email addresses; $view is a template name; $data is passed to the template.
- `queue($to, $subject, $view, $data)` — queue for sending asynchronously via a job. Returns the job id.

## SmsPort

Sending SMS messages.

```php
interface SmsPort
{
    public function send(string $to, string $message): void;
}
```

- `send($to, $message)` — send an SMS to a phone number.

## SessionPort

Per-visitor session state (login, preferences, flash data). The kernel defines the contract; a plugin provides the adapter.

```php
interface SessionPort
{
    public function start(?string $id = null): void;
    public function id(): string;
    public function get(string $key, mixed $default = null): mixed;
    public function put(string $key, mixed $value): void;
    public function has(string $key): bool;
    public function pull(string $key, mixed $default = null): mixed;
    public function push(string $key, mixed $value): void;
    public function increment(string $key, int $by = 1): int;
    public function forget(string $key): void;
    public function flush(): void;
    public function all(): array;
    public function flash(string $key, mixed $value): void;
    public function reflash(): void;
    public function token(): string;
    public function regenerateToken(): void;
    public function regenerate(): void;
    public function invalidate(): void;
    public function shouldPersist(): bool;
    public function save(): void;
}
```

- `start($id)` — load the session for the given id (null generates a fresh one).
- `id()` — current session id.
- `get($key, $default)`, `put($key, $value)`, `has($key)`, `pull($key, $default)` — basic get/set/has/pop operations.
- `push($key, $value)` — append a value to an array stored under $key.
- `increment($key, $by)` — increment a counter. Returns the new value.
- `forget($key)`, `flush()` — remove one key or clear everything.
- `all()` — all attributes.
- `flash($key, $value)` — store data for exactly one subsequent request.
- `reflash()` — keep all (or named) flash data alive for one more request.
- `token()` — the CSRF token for this session.
- `regenerateToken()` — mint a new CSRF token (without invalidating the session).
- `regenerate()` — new session id, keep data (defends against fixation after login).
- `invalidate()` — new session id AND wipe all data (logout).
- `shouldPersist()` — whether the session is worth saving (false for a stateless visitor that never wrote anything).
- `save()` — persist via the backing handler.

## LoggerPort

Application logging. PSR-3 shaped, not dependent.

```php
interface LoggerPort
{
    public function emergency(string|\Stringable $message, array $context = []): void;
    public function alert(string|\Stringable $message, array $context = []): void;
    public function critical(string|\Stringable $message, array $context = []): void;
    public function error(string|\Stringable $message, array $context = []): void;
    public function warning(string|\Stringable $message, array $context = []): void;
    public function notice(string|\Stringable $message, array $context = []): void;
    public function info(string|\Stringable $message, array $context = []): void;
    public function debug(string|\Stringable $message, array $context = []): void;
    public function log(string $level, string|\Stringable $message, array $context = []): void;
}
```

Each level corresponds to RFC 5424 severity. The $context array holds structured data; keys named in the message as `{placeholder}` are interpolated. Adapters must not throw — a logger that fails takes down the operation it was only meant to observe.

## LogLevel

RFC 5424 severity levels as an enum, in descending severity.

```php
enum LogLevel: string
{
    case Emergency;  // 'emergency'
    case Alert;      // 'alert'
    case Critical;   // 'critical'
    case Error;      // 'error'
    case Warning;    // 'warning'
    case Notice;     // 'notice'
    case Info;       // 'info'
    case Debug;      // 'debug'
    
    public function severity(): int;
    public function passes(self $minimum): bool;
    public static function parse(string $level): self;
}
```

- `severity()` — lower is more severe (0 = Emergency, 7 = Debug).
- `passes($minimum)` — true when this level is at least as severe as the minimum.
- `parse($level)` — parse a string, falling back to Debug for unrecognized values.

## ClockPort

Injectable time, for testable behaviour that depends on elapsed time.

```php
interface ClockPort
{
    public function now(): \DateTimeImmutable;
    public function timestamp(): int;  // UNIX timestamp
}
```

Use sparingly — only when elapsed time changes a decision (expiry, throttling, backoff, scheduling). Use `SystemClock` (shipped in the kernel) as the default; tests substitute a frozen implementation.

## SystemClock

The real clock. Shipped in the kernel so no plugin is needed for the default.

```php
final class SystemClock implements ClockPort
{
    public function now(): \DateTimeImmutable { ... }
    public function timestamp(): int { ... }
}
```

## TracerPort

Distributed tracing. OpenTelemetry shaped, dependency-free.

```php
interface TracerPort
{
    public function start(string $name, array $attributes = []): Span;
    public function measure(string $name, callable $work, array $attributes = []): mixed;
    public function continueFrom(string $traceparent): void;
    public function traceparent(): string;
}
```

- `start($name, $attributes)` — begin a span. Caller must end it. Prefer `measure()` for most uses.
- `measure($name, $work, $attributes)` — run $work inside a span, ending it on both success and exception. Returns the callback's return value. The standard form, used by the kernel internally.
- `continueFrom($traceparent)` — adopt an inbound W3C `traceparent` header so this process's spans join the caller's trace.
- `traceparent()` — the active span's traceparent (W3C format), or '' when not recording.

## Span

A timed unit of work inside a trace.

```php
interface Span
{
    public function setAttribute(string $key, string|int|float|bool $value): void;
    public function setAttributes(array $attributes): void;
    public function recordException(\Throwable $e): void;
    public function setError(string $description = ''): void;
    public function end(): void;
    public function traceparent(): string;
}
```

- `setAttribute($key, $value)`, `setAttributes($attributes)` — attach high-cardinality dimensions (user id, full path, SQL statement).
- `recordException($e)` — mark the span as failed. Does not end it; the caller may still add a fallback result.
- `setError($description)` — mark the outcome explicitly.
- `end()` — stop the clock and hand the span to the exporter. Idempotent.
- `traceparent()` — this span's id in W3C format, or '' when not recording.

## MetricsPort

Counters, gauges, and distributions for operational observability.

```php
interface MetricsPort
{
    public function counter(string $name, int|float $by = 1, array $labels = []): void;
    public function gauge(string $name, int|float $value, array $labels = []): void;
    public function histogram(string $name, int|float $value, array $labels = []): void;
    public function timing(string $name, float $milliseconds, array $labels = []): void;
}
```

- `counter($name, $by, $labels)` — add to a monotonically increasing count. $labels are low-cardinality dimensions (route path template, database, status). Adapters may drop or truncate high-cardinality labels.
- `gauge($name, $value, $labels)` — record a current value (queue depth, memory, pool size).
- `histogram($name, $value, $labels)` — one observation into a distribution. The backend keeps quantiles.
- `timing($name, $milliseconds, $labels)` — a duration in milliseconds. Separate from `histogram()` because most backends have dedicated timing with unit metadata.

Adapters must not throw.

## NullTracer, NullSpan, NullMetrics

No-op implementations shipped in the kernel. Used when no adapter is bound.

```php
final class NullTracer implements TracerPort
{
    public static function instance(): self;
    // All methods are no-op; measure() still runs $work
}

final class NullSpan implements Span
{
    public static function instance(): self;
    // All methods are no-op
}

final class NullMetrics implements MetricsPort
{
    public static function instance(): self;
    // All methods are no-op
}
```

These are stateless and shared (singletons). Writing kernel code unconditionally against the ports is safe — when tracing/metrics are off, the no-op path is two method calls on a shared immutable object.

## EncryptionPort

Symmetric authenticated encryption for sensitive data at rest.

```php
interface EncryptionPort
{
    public function encrypt(mixed $value, bool $serialize = true): string;
    public function decrypt(string $payload, bool $unserialize = true): mixed;
    public function encryptString(string $value): string;
    public function decryptString(string $payload): string;
}
```

- `encrypt($value, $serialize)` — encrypt a value. When $serialize is true, non-strings are serialized first. Returns a string (usually base64).
- `decrypt($payload, $unserialize)` — decrypt. Throws on tampering or an unknown key.
- `encryptString($value)`, `decryptString($payload)` — convenience methods for strings (no serialization).

Implementations must use authenticated encryption (tamper-evident) and support key rotation so old ciphertext stays decryptable after a key change.

## HashingPort

One-way password hashing (bcrypt, argon2).

```php
interface HashingPort
{
    public function make(string $value, array $options = []): string;
    public function check(string $value, string $hashedValue): bool;
    public function needsRehash(string $hashedValue, array $options = []): bool;
}
```

- `make($value, $options)` — hash a password with salt and work factor. $options carry algorithm parameters. Use this for credentials, never plain hashes (sha256/md5).
- `check($value, $hashedValue)` — verify a plaintext value against a stored hash (timing-safe).
- `needsRehash($hashedValue, $options)` — whether the stored hash should be re-made with current options (e.g., cost changed).

## Registering port implementations

Bind implementations in the kernel bootstrap via `withPorts()`:

```php
Kernel::configure()
    ->withPorts([
        DatabasePort::class  => new MySQLAdapter(config('database')),
        CachePort::class     => new RedisAdapter(config('cache')),
        QueuePort::class     => new RedisQueueAdapter(config('jobs')),
        MailPort::class      => new SendGridMailGateway(config('sendgrid')),
        // ... etc
    ])
```

Bindings merge, so later calls override:

```php
$builder->withPorts([...]);  // first set
$builder->withPorts([...]);  // adds to or overrides
```

## Writing a port adapter

Implement the interface and handle all documented contracts. For ports used by the error pipeline or observability (LoggerPort, MetricsPort, TracerPort), never throw — an observation that fails is worse than losing the observation.

Example cache adapter:

```php
<?php declare(strict_types=1);
namespace App\Infrastructure\Cache;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\{CachePort, Lock};

final class InMemoryCacheAdapter implements CachePort
{
    private array $store = [];
    private array $expiry = [];

    public function get(string $key): mixed
    {
        if (!isset($this->store[$key])) {
            return null;
        }
        if (isset($this->expiry[$key]) && $this->expiry[$key] < time()) {
            unset($this->store[$key], $this->expiry[$key]);
            return null;
        }
        return $this->store[$key];
    }

    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        $this->store[$key] = $value;
        if ($ttl !== null) {
            $this->expiry[$key] = time() + $ttl;
        }
        return true;
    }

    // ... etc
}
```

## Testing with port fakes

Create in-memory fakes for testing, never substitute real adapters:

```php
$cache = new InMemoryCacheAdapter();
$db = new InMemoryDatabaseAdapter();

$service = new InvoiceService(
    repository: new InvoiceRepository($db),
    cache: $cache,
);

$result = $service->generate();
$this->assertTrue($cache->has('invoice.pending'));
```

## Required ports at boot

`RegisterPortsStage` runs at boot and verifies every port the kernel needs is bound. These are strictly required:

- `DatabasePort`
- `CachePort`

All other ports are optional. Some have kernel-level defaults if not bound:

- `ClockPort` — defaults to `SystemClock` (in the Scheduler)
- `TracerPort` — defaults to `NullTracer` (in the HttpPipeline)
- `MetricsPort` — defaults to `NullMetrics` (in the HttpPipeline)
- `LoggerPort` — optional (checked where used, not at boot)

Ports are **not** listed in `module.json` `requires[]`: that list holds module domains only, and a port class there is an unknown domain that fails the boot. Every bound port is resolvable from every module.

## Source

- [Ports directory](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Ports/)
- [RegisterPortsStage](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Boot/Stages/RegisterPortsStage.php)
- [CoreContainer](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Container/CoreContainer.php)
