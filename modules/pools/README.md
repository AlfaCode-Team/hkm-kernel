# alfacode-team/pools

A fixed-capacity pool of lazily created resources. No dependencies, no driver
knowledge, no retry knobs.

```php
use AlfacodeTeam\Pools\Pool;

$pool = new Pool(
    name:    'central',
    size:    20,
    init:    fn () => new MultiDriverDatabaseAdapter($config),
    timeout: 5.0,
    adapter: Pool::adapterForRuntime(),
);

// The intended entry point: borrow for ONE operation, return automatically.
$rows = $pool->use(fn (DatabasePort $db) => $db->query('SELECT 1'));
```

## Why per-operation borrowing

The obvious way to pool connections is one per **request**: borrow at the start,
return at the end. That caps concurrency at the pool size — twenty slots means
twenty requests in flight, however little of each request is actually spent
talking to the database. On a page that does 15 ms of queries inside a 300 ms
response, nineteen of those twenty sockets sit idle while a waiter times out.

`use()` borrows for one **operation**. The same twenty slots then serve roughly
twenty times the concurrency, and a slow renderer no longer holds a connection
hostage. That is the whole reason this package exists separately from an adapter
that happens to keep a socket around.

**It is not a free switch.** `lastInsertId()` is connection-local, so an
`INSERT` and the `lastInsertId()` that follows it MUST be on the same
connection, and a transaction spans many operations. A consumer that exposes
either has to pin for that span — see "Migrating an existing pool" below.

## The two runtimes

| Adapter | For | `pop()` waits? |
|---|---|---|
| `Adapter\Stack` | PHP-FPM, CLI, tests | no — nothing can return a resource while the caller waits |
| `Adapter\Coroutine` | Swoole / OpenSwoole | yes — parks on a channel and yields the scheduler |

`Pool::adapterForRuntime()` picks. It checks whether the caller is **inside a
coroutine**, not merely whether the extension is loaded: a Swoole worker's own
bootstrap is not, and parking there blocks the whole worker instead of yielding
it.

The split exists so the pool itself holds no `class_exists('\Swoole…')` checks.
Without it the FPM path carries a spin-sleep loop it can never benefit from,
while the coroutine path is never actually exercised against a channel.

On a non-blocking adapter, saturation **fails immediately** rather than spinning
at full CPU until the timeout. That is also the honest answer: with one
execution context nothing can return a resource while this caller waits, so
saturation means this caller is holding them — nested `use()` — and waiting
cannot change that.

## Capacity is reserved before the resource exists

`$reserved` counts resources that exist **plus creations in flight**. Counting
live objects instead lets two coroutines both observe "under the limit" and both
connect, so a pool of 20 opens 21+ sockets — a bug that only appears when the
pool matters.

Every path out of a reservation releases it, or the slot is lost for the life of
the process. Three mechanisms, because a lost slot is permanent:

- the `finally` around creation, so a failed connect does not shrink the pool —
  a database down for a minute would otherwise leave a crippled pool behind once
  it recovered;
- `release($connection, failed: true)` → `recover` → `reclaim` → `destroy` →
  `forget`, where `forget()` is reached only when `destroy()` itself threw;
- `destroy()` only releases capacity the pool is actually holding, so a double
  destroy or a foreign connection cannot drive `$reserved` below the truth and
  let the pool exceed its size.

## No retry knobs

`$timeout` bounds how long `pop()` waits for a resource to become **available**.
Creating one takes as long as `$init` takes, because a pool cannot interrupt a
blocking connect — give `$init` its own connect timeout if the total has to be
bounded.

A creation failure **propagates untouched** and never falls through to a wait.
Waiting for someone else's connection after your own connect failed is a retry
in disguise, and it loses the original exception: the caller gets "pool timed
out" instead of "connection refused", which points at entirely the wrong thing.
Retry policy belongs in `$init`, where `alfacode-team/circuit-breaker` composes.

## `maintain()` — keeping idle resources alive

A pooled resource nobody checks out is not a resource nothing happens to: the
server is still counting silence, and anything that reaps an idle peer (MySQL's
`wait_timeout`, eight hours by default) hands the next caller a socket that is
already gone — as a **mid-request error** rather than a connect failure.

The usual fix is to validate on **borrow**, which puts a `SELECT 1` round trip
on the hot path of every single operation. `maintain()` moves it off: a host
calls it on an interval shorter than the shortest server-side deadline it holds,
and the request path pays nothing.

Duck-typed on `ping()`, as `recover()` is on `reset()`/`reconnect()` and
`close()` on `close()`/`disconnect()`: this package depends on no driver, so
capabilities are discovered rather than declared. A resource without one is left
alone, which is what makes it safe to call on every pool a host owns.

Resources are drained before being pinged, then returned one at a time — not
under a single lock, because a ping writes to the network and holding the pool's
lock across that would stall every `pop()`. Draining first matters because
`Stack` is LIFO: popping and pushing one at a time hands the same resource
straight back and leaves every other one unswept, which is a sweep that looks
like it works and only ever touches one connection.

## `Group` — named pools, with a bound

```php
$group = new Group(maxPools: 200);

$pool = $group->remember("tenant:{$id}", fn () => new Pool("tenant:{$id}", 5, $init));

$group->use(['central', "tenant:{$id}"], fn ($central, $tenant) => /* … */);
```

A multi-tenant worker creates a pool per tenant on demand and would otherwise
hold one pool's worth of sockets for every tenant it has **ever** served.
`maxPools` closes the least recently used pool when a new one needs room;
`get()` touches, so eviction follows use rather than creation order and the pool
serving most of the traffic is the last to go. `0` means unbounded.

`Group::use()` nests `Pool::use()` calls, so every resource is returned however
the callback **or a later acquisition** fails — which is the reason to have it
rather than acquiring in a loop.

## `Connection` holds its pool weakly

A strong back-reference is an uncollectable cycle. The path from pool to
connection runs through the adapter, and with the coroutine adapter that is a
`Coroutine\Channel` whose contents live in C memory — PHP's collector cannot
traverse it, so it could never prove the cycle unreachable, and the channel's own
reference kept every pool, connection and socket alive for the life of the
process.

Holding it weakly keeps the graph acyclic, so dropping a pool releases its
resources by refcount. Reclaiming or destroying a connection whose pool is gone
is a no-op.

## Telemetry

`Telemetry` is two methods — `histogram(name, seconds, labels)` and
`gauge(name, value, labels)` — and the default is `NullTelemetry`. A pool that
required a metrics library would make that library a dependency of the
framework; the host adapts whatever it already has (the kernel's `MetricsPort`,
OpenTelemetry, StatsD) in about fifteen lines.

Emitted: `pool.connection.wait_time`, `pool.connection.use_time`,
`pool.connection.{active,idle,capacity}.count`.

Gauges are **pushed** on each state change rather than observed lazily, because
a push-model port cannot register an observer. The cost is one call per
checkout, skipped entirely when telemetry is the null implementation.

## Migrating an existing pool

`Pool` is a drop-in for the **engine** of a request-pinned pool, not for its
borrowing semantics. A consumer whose port exposes `lastInsertId()` or
transactions must keep pinning for that span; what it gains from switching is
slot reservation, the two runtime adapters, the recovery chain, `maintain()`
instead of validate-on-borrow, the LRU bound in `Group`, and telemetry.

Switch the borrowing model separately, deliberately, and only for operations
that are provably self-contained.

## What is not verified here

`Adapter\Coroutine` has **no unit tests and is not statically analysed**.
`Coroutine\Channel` and `Coroutine\Lock` are declared by a PHP extension, not by
any source on the include path, so neither the test suite nor PHPStan can see
them on a machine without the extension. Every extension call goes through one
dynamic-dispatch helper rather than being asserted with an ignore comment, and
the class says so in its own docblock.

`Adapter\Stack` and `Pool` are covered exhaustively (49 tests). The channel
semantics the coroutine adapter relies on — that a push wakes a parked pop, that
a zero timeout means "forever" — are claims about the extension, and claims about
the extension can only be tested against the extension. **Treat it as unproven
until it has run under a Swoole worker.**

## License

MIT.
