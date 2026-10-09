# Cache and locks — performance and concurrency

`CachePort` provides caching and distributed mutual exclusion. Unlike simple counters, locks are ownership-verified and TTL-bounded, making them safe for job idempotency and preventing cache stampedes across multi-worker deployments.

## CachePort methods

See [Ports](/infrastructure/ports#cacheport) for the complete interface.

### Basic operations

```php
$value = $cache->get('user.42.profile');       // or null if absent/expired
$cache->set('user.42.profile', $profile, 3600);  // TTL in seconds
$cache->has('user.42.profile');
$cache->delete('user.42.profile');
$cache->flush();                                // clear entire cache
```

### remember() — cache-aside pattern

Fetch or compute and cache:

```php
$profile = $cache->remember('user.42.profile', 3600, function () {
    return $db->queryOne('SELECT * FROM users WHERE id = ?', [42]);
});
```

If the key exists, it is returned immediately. If absent or expired, the callback runs, its return value is cached for 3600 seconds, and returned to the caller.

### increment() — atomic counters

Increment a counter atomically (no read/write race):

```php
$viewCount = $cache->increment('article.42.views');        // +1
$viewCount = $cache->increment('article.42.views', 5);     // +5

// NOT a substitute for lock() — it has no ownership, no blocking, no TTL release
```

### deletePattern() — wildcard delete

Delete keys matching a pattern (e.g., after a user modifies their profile):

```php
$count = $cache->deletePattern('user.42.*');  // invalidate all profile caches
```

Not all adapters support this; check the adapter's documentation.

## Lock — distributed mutual exclusion

A lock is ownership-verified and TTL-bounded, making it safe for:

- **Single-flight work**: only one worker processes the same task
- **Job idempotency**: retrying a job doesn't duplicate side effects
- **Cache stampede prevention**: one slow callback rebuilds the cache while others wait
- **Scheduled tasks**: only one node runs the daily report

### Typical usage

```php
// Try once without waiting
$lock = $cache->lock('report:nightly', 300);  // 300s TTL
if (!$lock->acquire()) {
    return;  // another worker owns it — do nothing
}

try {
    $this->generateNightly();
} finally {
    $lock->release();
}
```

### block() — wait with a callback

Blocking acquisition with automatic release:

```php
$result = $cache->lock('report:nightly', 300)
    ->block(10, fn() => $this->generateNightly());

// Waits up to 10s, runs the callback while holding the lock,
// ALWAYS releases afterwards (even if the callback throws)
```

If `$lock->block(10)` is called without a callback, it waits up to 10 seconds and returns true once acquired — the caller then owns the release.

### Ownership is the point

Every lock carries an owner token. `release()` only succeeds for the holder:

```php
$lock = $cache->lock('task', 300);
$lock->acquire();
$owner = $lock->owner();  // unguessable token

// Later, in another request or process:
$lock2 = $cache->restoreLock('task', $owner);
$lock2->release();  // only works because we passed the correct owner
```

Without ownership verification, a process whose lock expired cannot delete the lock a different process just acquired. Implementations must make the check-and-delete atomic (Redis uses Lua scripts).

### Graceful timeout

```php
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\LockTimeoutException;

try {
    $cache->lock('report:nightly', 300)->block(10);
} catch (LockTimeoutException $e) {
    // Another worker held the lock for > 10 seconds
    // Skip this run and try again next minute
}
```

## AbstractLock

Base class for Lock implementations. Subclasses implement only the store-specific operations; the `block()` logic (including timeout and sleep) is shared across all backends.

```php
abstract class AbstractLock implements Lock
{
    public function block(int $seconds, ?callable $callback = null): mixed { ... }
    protected static function randomOwner(): string { ... }
    protected function sleep(int $micros): void { ... }
}
```

The sleep method is coroutine-aware: under OpenSwoole/Swoole it yields the coroutine; otherwise it uses `usleep()`. This ensures a waiting lock never stalls a worker.

## LockTimeoutException

Thrown when `lock->block($seconds)` exceeds the deadline.

```php
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\LockTimeoutException;

try {
    $cache->lock('task', 300)->block(5);
} catch (LockTimeoutException $e) {
    // Timed out
}
```

## Patterns

### Cache stampede prevention

When a hot cache key expires, every pending request rebuilds it. Lock-back a rebuild:

```php
$data = $cache->remember('config.published', 3600, function () use ($cache) {
    $lock = $cache->lock('config.rebuild', 300);
    if (!$lock->acquire()) {
        // Another request is rebuilding — wait for it
        sleep(1);
        return $cache->get('config.published') ?? [];
    }

    try {
        return $db->query('SELECT * FROM config WHERE published = ?', [true]);
    } finally {
        $lock->release();
    }
});
```

Better: let the first request rebuild while others wait:

```php
$data = $cache->lock('config.rebuild', 300)
    ->block(5, fn() => $cache->remember('config.published', 3600, fn() =>
        $db->query('SELECT * FROM config WHERE published = ?', [true])
    ));
```

### Idempotent job retries

Mark a job as complete in the cache before returning. If the job is retried, it exits early:

```php
// In the job's handle() method
$jobKey = 'job:' . $this->jobId();
if ($cache->has($jobKey)) {
    return JobResult::skipped('Already processed');
}

// Do the work...

$cache->set($jobKey, true, 86400);  // 1 day retention
return JobResult::success();
```

For even stronger guarantees, use a database flag instead of cache.

### Scheduled task overlap prevention

The Scheduler uses locks to prevent multiple workers from running the same task:

```php
// In module.json, under "schedule": [ ... ]
{
    "name": "reports.nightly",
    "at": "0 2 * * *",
    "job": "reports.generate-nightly",
    "withoutOverlapping": true,
    "expiresAfter": 1800
}
```

The Scheduler takes a lock before dispatching; if another worker holds it, the task is skipped.

### Rate limiting via counters

Track requests per user without a DB call:

```php
$key = "rate:user_{$userId}:requests";
$count = $cache->increment($key);

if ($count === 1) {
    // First increment this window — set TTL
    $cache->set($key, 1, 60);  // reset every 60s
}

if ($count > 100) {
    throw new TooManyRequestsException();
}
```

For production rate limiting, use the `throttle` route filter from the SecurityFilters plugin.

## Testing with locks

Create an in-memory fake:

```php
<?php declare(strict_types=1);
namespace Tests\Fixtures;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\{CachePort, Lock};

final class InMemoryCacheAdapter implements CachePort
{
    private array $store = [];
    private array $locks = [];

    public function lock(string $name, int $seconds = 0, ?string $owner = null): Lock
    {
        $owner ??= bin2hex(random_bytes(8));
        return new InMemoryLock($this->locks, $name, $seconds, $owner);
    }

    // ... other methods
}

final class InMemoryLock extends AbstractLock
{
    public function __construct(
        private array &$locks,
        string $name,
        int $seconds,
        string $owner,
    ) {
        parent::__construct($name, $seconds, $owner);
    }

    public function acquire(): bool
    {
        if (!isset($this->locks[$this->name])) {
            $this->locks[$this->name] = $this->owner;
            return true;
        }
        return $this->locks[$this->name] === $this->owner;
    }

    public function release(): bool
    {
        if (!isset($this->locks[$this->name])) {
            return false;
        }
        if ($this->locks[$this->name] !== $this->owner) {
            return false;
        }
        unset($this->locks[$this->name]);
        return true;
    }

    public function forceRelease(): void
    {
        unset($this->locks[$this->name]);
    }
}
```

## Deployment notes

**Cache backends**: Choose one that supports atomic operations (Redis, Memcached). File-based caches do not support atomic increment or locking and should only be used for development.

**TTL and lock duration**: Set a lock TTL high enough to cover worst-case execution (e.g., 5 minutes for a background job), but low enough that a crashed worker doesn't strand the lock forever. 15–300 seconds is typical.

**Monitoring**: Track lock contention via metrics:

```php
$lock = $cache->lock('task', 300);
if (!$lock->acquire()) {
    $metrics->counter('task.lock.contention');
    return;
}
```

## Common mistakes

::: warning

**Using increment() for locking.** `increment()` is atomic but has no ownership, blocking, or TTL-based release. If the process dies after reading the counter, it stays high forever. Use `lock()` instead.

**Not setting a lock TTL.** A crashed process with `$seconds = 0` (no expiry) strands the lock. Always set a TTL.

**Assuming cache is persistent.** Cache is ephemeral — entries may be evicted, the cache may restart, or the backend may fail. Never use cache alone for authoritative state; use the database.

**Sharing a lock across requests.** Locks obtained with `lock()` are request-scoped; a static reference would leak them across requests under OpenSwoole.

:::

## Source

- [CachePort](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Ports/CachePort.php)
- [Lock](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Ports/Lock.php)
- [AbstractLock](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Ports/AbstractLock.php)
- [LockTimeoutException](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Exceptions/LockTimeoutException.php)
