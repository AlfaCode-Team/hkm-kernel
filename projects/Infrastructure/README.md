# `Project\Infrastructure` — Project-Supplied Port Adapters

> Namespace `Project\Infrastructure\` → `projects/Infrastructure/`.

The kernel defines **port interfaces**; the project supplies the
**implementations** and binds them in `bootstrap/app.php` via `->withPorts([...])`.
These adapters are the dependency-free defaults, so the framework boots and runs
end-to-end with **zero external infrastructure** — no MySQL, no Redis, no broker.

They are deliberately modest: correct, daemon-free defaults for dev, CI and small
deployments. Point them at real infrastructure (or let a plugin override the
binding) in production.

| Class | Implements | Default for |
|---|---|---|
| `PdoDatabase` | `DatabasePort` | SQL access via PDO (SQLite in-memory by default) |
| `LazyDatabasePort` | `DatabasePort` | Defers building the real adapter until first use |
| `FileCache` | `CachePort` | Cross-process cache on disk |
| `InMemoryCache` | `CachePort` | Per-process cache (no persistence) |
| `FileLock` | `Lock` (`AbstractLock`) | Cross-process lock (single machine) |
| `ProcessLocalLock` | `Lock` (`AbstractLock`) | Single-process lock — **not distributed** |
| `FileQueue` | `QueuePort` | Cross-process job queue on disk |
| `LazyMailPort` | `MailPort` | Defers building the mailer/SMTP transport |

---

## `PdoDatabase` — the `DatabasePort` default

```php
DatabasePort::class => new PdoDatabase(
    dsn:      env('DB_DSN', 'sqlite::memory:'),
    username: env('DB_USERNAME'),
    password: env('DB_PASSWORD'),
),
```

Implements the whole port: `query()`, `queryOne()`, `execute()`, `upsert()`,
`lastInsertId(?string $sequence)`, `beginTransaction()`, `commit()`, `rollback()`,
`inTransaction()`.

- `upsert()` compiles the **driver-correct** statement — `ON DUPLICATE KEY UPDATE`
  on MySQL, `ON CONFLICT … DO UPDATE` on PostgreSQL/SQLite. Never hand-write
  either clause in a repository; call `$db->upsert()`.
- `\PDOException` never escapes a repository — translate it to
  `RepositoryException` at the repository boundary.
- **Swoole:** one instance per worker, created in `bootstrap/app.php`, alive for
  the worker's lifetime. PDO handles are not shared across workers, so this is
  safe. It is not a static singleton.

## `LazyDatabasePort` / `LazyMailPort` — build on first use

Both wrap a `Closure` factory and memoise the resolved port, so wiring a
notifier does not force a connection at bootstrap:

```php
DatabasePort::class => new LazyDatabasePort(
    static fn (): PdoDatabase => new PdoDatabase(env('DB_DSN'), …),
),
```

`DatabaseErrorLogger` and `MailNotifier` only touch their backend when an error
of the configured severity actually fires — on the happy path neither the DB
connection nor the SMTP transport is ever opened. Every port method proxies
through to the resolved instance.

---

## Cache adapters

### `FileCache` — cross-process, the correct default

One file per key under the directory you pass (e.g. `var/cache/data`), holding a
serialized `{key, value, expires}` record.

```php
CachePort::class => new FileCache($projectRoot . '/var/cache/data'),
```

Entries survive the end of a request, which is what a password-reset OTP, a reset
token or a rate-limit counter actually requires: under PHP-FPM the write and the
read happen in **different processes**, so an in-memory store makes every one of
them fail on first read — looking exactly like an expired entry.

Full port: `get`, `set`, `delete`, `has`, `remember`, `increment`,
`deletePattern`, `flush`, plus `lock()` / `restoreLock()` returning a
[`FileLock`](#filelock--cross-process-lock).

Trade-offs: every write takes an exclusive lock, and `deletePattern()`/`flush()`
scan the directory — not a high-throughput cache. Values use `serialize()`, so
the directory must be trusted, application-owned storage under `var/`, never in
the docroot. Set `REDIS_HOST` in production; the RedisCache plugin overrides the
binding.

### `InMemoryCache` — per-process

```php
CachePort::class => static fn(): InMemoryCache => new InMemoryCache(),
```

Same port surface, state held in an array. State is **per worker** and dies with
the request under FPM. Use it for tests, single-process CLI runs and caches whose
loss is harmless — never for anything that must be read back by a later request.
Its `lock()` returns a `ProcessLocalLock`, which is exactly as strong as the
store behind it.

---

## Locks

### `FileLock` — cross-process lock

Acquire is `fopen($file, 'x')` (`O_CREAT|O_EXCL`), which the kernel guarantees is
atomic on a local filesystem: exactly one caller creates the file, every other
gets `false`. Release takes `flock(LOCK_EX)` around read-owner-then-unlink so the
compare and the delete cannot interleave with another process acquiring after the
TTL expired. API: `acquire()`, `release()`, `forceRelease()` (+ the `AbstractLock`
helpers).

Limits — read before deploying:

- `O_EXCL` is **not reliable on NFS**. On a shared/network filesystem use Redis.
- An expired lock is reclaimed lazily by the next acquirer, so a crashed holder
  blocks others until its TTL passes — **always pass a real TTL**.

### `ProcessLocalLock` — ⚠ not a distributed lock

Valid only inside one PHP process. Under PHP-FPM two concurrent requests are two
processes, so both "acquire" the same lock and both enter the critical section;
under OpenSwoole it holds within one worker and fails across workers. It exists
because `CachePort` must implement the whole contract, and it is genuinely
correct for single-process test and CLI runs. Use `FileLock` (single machine) or
the RedisCache plugin's `RedisLock` (cross-machine) for anything real.

---

## `FileQueue` — the `QueuePort` default

Jobs are appended as JSON lines to one file per queue under the directory you
pass (e.g. `var/queue`).

```php
QueuePort::class => new FileQueue($projectRoot . '/var/queue', defaultMaxAttempts: 3),
```

Implements the full lifecycle — `push()`, `later()`, `size()`, `pop()`, `ack()`,
`release()`, `fail()` — and rebuilds a `JobPayload` from the stored record, so a
job pushed by a web request is popped by a separate `php app/worker/run.php`
process. That is enough to run the worker end to end without Redis/SQS.

Not a broker: writes take an exclusive lock and `pop()` rewrites the file. Swap
for the Redis-backed adapter in production (it overrides this binding when
`REDIS_HOST` is set) — the worker entry point needs no change.

---

## Rules

```
✓ The kernel owns the port INTERFACE; the project owns the IMPLEMENTATION and binds it in withPorts().
✓ These are dependency-free defaults so the framework boots with no external services.
✓ Cross-request state (OTPs, reset tokens, rate limits) needs FileCache or Redis — never InMemoryCache.
✓ Pass a real TTL to any lock; a crashed holder is only released when the TTL passes.
✓ One adapter instance per worker, created in bootstrap — never a static singleton.
✗ ProcessLocalLock for anything concurrent — it races silently in production.
✗ Hand-written ON DUPLICATE KEY / ON CONFLICT in a repository — call $db->upsert().
✗ Letting \PDOException escape a repository — translate it to RepositoryException.
✗ Putting the cache/queue directories anywhere readable by the web server — they live under var/.
✗ Business logic in an adapter — it translates a port call to a backend call, nothing more.
```
