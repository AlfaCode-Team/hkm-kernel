# alfacode-team/circuit-breaker

Stops calling a dependency that is already failing. Framework-agnostic: no
kernel, no cache library, no HTTP client.

## Why

A dependency that is **down** is cheap — the connection is refused in
microseconds. One that is **sick** is what takes an application with it: it
accepts the connection and never answers, so every call costs the full socket
timeout. Under PHP-FPM a worker is occupied for the whole request, so 32 workers
× a 5-second timeout is a site that is down because a third party is slow.
Retries make it worse by multiplying load on something already struggling.

## Install

```bash
composer require alfacode-team/circuit-breaker
```

## Use

```php
use AlfacodeTeam\CircuitBreaker\{CircuitBreaker, BreakerPolicy};
use AlfacodeTeam\CircuitBreaker\Store\InMemoryStateStore;

$breaker = new CircuitBreaker(
    'stripe',
    new InMemoryStateStore(),              // share state across processes in production
    new BreakerPolicy(threshold: 5, window: 60, cooldown: 30),
);

$result = $breaker->call(fn () => $stripe->paymentIntents->create($payload));
```

When the circuit is open, `call()` throws `CircuitOpenException` **without
touching the dependency**.

### Judging results, not just exceptions

Most dependency failures are not exceptions. An HTTP client returns `503` as an
ordinary response, so an exception-only breaker never notices the clearest
unhealthy signal there is:

```php
$response = $breaker->callWith(
    fn () => $client->get($url),
    fn ($r) => $r->status() < 500 && $r->status() !== 429,   // false records a failure
);
```

The result is returned either way. A recorded failure moves the circuit, never
the caller's answer.

### The failure predicate is the part that matters

Counting every exception is wrong in a way that only shows up in production: a
remote API correctly answering `422` to malformed input would trip the circuit,
so a run of bad user input takes out a healthy dependency.

```php
$policy = (new BreakerPolicy(threshold: 5))
    ->failingOn(ConnectException::class, TimeoutException::class);
```

A breaker is for failures that mean *the dependency is unhealthy*. A `404` or a
`422` is the dependency working correctly and saying no.

## States

```
Closed ──(threshold failures in window)──> Open
Open   ──(cooldown elapses)─────────────-> HalfOpen
HalfOpen ──(trial succeeds)─────────────> Closed
HalfOpen ──(trial fails)────────────────> Open, cooldown restarts
```

`HalfOpen` admits at most `trialCalls` requests — claimed atomically, so exactly
that many get through across *every* process. Without it, recovery means the
full load arriving the instant the cool-down elapses, which knocks the
dependency straight back down.

## Sharing state

`StateStore` declares the three operations the breaker needs. **`increment()`
must be atomic across processes** — Redis `INCR`, `apcu_inc` or a locked
read-modify-write all qualify; `get()` then `set()` does not. If two workers can
each read 4, add one and write 5, a breaker with a threshold of 5 never trips
under exactly the concurrent load that matters.

`InMemoryStateStore` is for tests and single long-lived processes. Under PHP-FPM,
back it with Redis.

## Design notes

- **Fails open.** If the state store itself breaks, every call is allowed through
  and the breaker effectively disappears. A protective component whose own outage
  takes down the thing it protects has become the attack.
- **No writes on the happy path.** One store read per call while Closed. Failure
  counting is a rolling window precisely so a success needs no write to reset
  anything.
- **The original exception is never wrapped.** A caller must still be able to
  tell a timeout from a 404.
- **Time is injectable.** Every interesting behaviour is time-dependent, and a
  test that sleeps out a 30-second cool-down is a test nobody runs.
- **State changes are announced** via `onStateChange`, because a circuit tripping
  is an operational event and a breaker nobody can see silently hides an outage.

## Test

```bash
composer install && composer test
```

## Licence

MIT.
