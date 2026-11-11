<?php

declare(strict_types=1);

namespace AlfacodeTeam\Pools;

use AlfacodeTeam\Pools\Adapter\Coroutine;
use AlfacodeTeam\Pools\Adapter\Stack;
use AlfacodeTeam\Pools\Exception\PoolTimeoutException;
use AlfacodeTeam\Pools\Telemetry\NullTelemetry;

/**
 * A fixed-capacity pool of lazily created resources.
 *
 * ─── WHAT THIS IS FOR, AND THE MISTAKE IT AVOIDS ────────────────────────────
 *
 * The obvious way to pool connections is one per REQUEST: borrow at the start,
 * return at the end. That caps concurrency at the pool size — twenty slots
 * means twenty requests in flight, however little of each request is actually
 * spent talking to the database. On a page that does 15 ms of queries inside a
 * 300 ms response, nineteen of those twenty sockets are idle while a waiter
 * times out.
 *
 * {@see use()} is the alternative: borrow for one OPERATION, return
 * immediately. The same twenty slots then serve the twenty-times-higher
 * concurrency that the arithmetic above implies, and a slow renderer no longer
 * holds a connection hostage. That is the whole reason this package exists
 * separately from an adapter that happens to keep a socket around.
 *
 * ─── NO RETRY KNOBS ─────────────────────────────────────────────────────────
 *
 * `$timeout` bounds how long {@see pop()} waits for a resource to become
 * AVAILABLE. Creating one takes as long as `$init` takes, because a pool cannot
 * interrupt a blocking connect — give `$init` its own connect timeout if the
 * total has to be bounded.
 *
 * If creation fails the exception propagates untouched. Retrying a failed
 * connect is `$init`'s business: only the caller knows whether the failure is
 * worth retrying and on what schedule, and `alfacode-team/circuit-breaker`
 * composes there. A pool that counted attempts would be guessing on their
 * behalf, and a creation failure that fell through to a WAIT would be a retry
 * in disguise — waiting for someone else's connection after your own connect
 * failed, with the original exception thrown away.
 *
 * ─── CAPACITY IS RESERVED BEFORE THE RESOURCE EXISTS ────────────────────────
 *
 * `$reserved` counts resources that exist PLUS creations in flight, which is
 * what makes the ceiling hold under concurrency. Counting live objects instead
 * lets two coroutines both observe "under the limit" and both connect, so a
 * pool of 20 opens 21+ sockets under load — a bug that only appears when the
 * pool matters. Every path out of a reservation must release it, or the slot is
 * lost for the life of the process; that is what the `finally` in {@see pop()}
 * and the {@see forget()} fallback are for.
 *
 * @template TResource
 */
final class Pool
{
    /**
     * Checked-out connections by id.
     *
     * @var array<string, Connection<TResource>>
     */
    private array $active = [];

    /** Resources that exist, plus creations in flight. Never exceeds $size. */
    private int $reserved = 0;

    /**
     * When each checked-out connection was requested, so the pool measures use
     * time itself rather than callers passing timestamps back in.
     *
     * @var array<string, float>
     */
    private array $checkedOutAt = [];

    private readonly Telemetry $telemetry;

    /** @var array<string, int|string> */
    private readonly array $labels;

    private bool $closed = false;

    /**
     * @param string                $name    identifies the pool in errors, ids and telemetry
     * @param int                   $size    maximum resources in existence at once
     * @param \Closure(): TResource $init    creates one resource; owns any retry policy
     * @param float                 $timeout seconds pop() may wait for an AVAILABLE resource
     */
    public function __construct(
        public readonly string $name,
        public readonly int $size,
        private readonly \Closure $init,
        public readonly float $timeout = 5.0,
        private readonly Adapter $adapter = new Stack(),
        ?Telemetry $telemetry = null,
    ) {
        if ($size < 1) {
            throw new \InvalidArgumentException("Pool [{$name}] size must be at least 1, got {$size}.");
        }

        if ($timeout < 0) {
            throw new \InvalidArgumentException("Pool [{$name}] timeout cannot be negative, got {$timeout}.");
        }

        // Starts EMPTY. Resources are created on demand, never pre-filled: a
        // warm-up opens sockets a process may never use, and under FPM — where
        // the process handles one request — it is pure added latency on the
        // first one.
        $this->adapter->initialize($size);

        $this->telemetry = $telemetry ?? new NullTelemetry();
        $this->labels    = ['pool' => $name, 'size' => $size];
    }

    /**
     * The right adapter for the current runtime.
     *
     * A coroutine extension being LOADED is not enough — a Swoole worker's own
     * bootstrap runs outside any coroutine, and parking there blocks the whole
     * worker rather than yielding. So this asks whether the caller is actually
     * inside one.
     */
    public static function adapterForRuntime(): Adapter
    {
        return Coroutine::available() && Coroutine::inCoroutine() ? new Coroutine() : new Stack();
    }

    /**
     * Run $callback with a borrowed resource, returning it afterwards.
     *
     * THE INTENDED ENTRY POINT. {@see pop()} and {@see push()} are for callers
     * that must own the lifetime themselves, and every one of those is a place
     * a resource can be leaked.
     *
     * A callback that throws marks the resource as suspect: the pool tries to
     * recover it and discards it when it cannot — see {@see release()}.
     *
     * @template T
     * @param  callable(TResource): T $callback
     * @return T
     */
    public function use(callable $callback): mixed
    {
        $connection = null;
        $failed     = false;

        try {
            $connection = $this->pop();

            return $callback($connection->resource);
        } catch (\Throwable $e) {
            $failed = true;

            throw $e;
        } finally {
            if ($connection instanceof Connection) {
                $this->release($connection, $failed);
            }
        }
    }

    /**
     * Take a resource, creating one if there is spare capacity and otherwise
     * waiting up to $timeout for one to come back.
     *
     * @return Connection<TResource>
     * @throws PoolTimeoutException when nothing becomes available in the budget
     */
    public function pop(): Connection
    {
        if ($this->closed) {
            throw new \LogicException("Pool [{$this->name}] is closed.");
        }

        $start    = microtime(true);
        $deadline = $start + $this->timeout;

        try {
            while (true) {
                $slot = (bool) $this->adapter->synchronized(function (): bool {
                    if ($this->adapter->count() === 0 && $this->reserved < $this->size) {
                        $this->reserved++;

                        return true;
                    }

                    return false;
                });

                if ($slot) {
                    return $this->createInReservedSlot($start);
                }

                $resource = $this->adapter->pop(max(0.0, $deadline - microtime(true)));

                if ($resource instanceof Connection) {
                    $this->track($resource, $start);

                    return $resource;
                }

                // An adapter that cannot block has already given its final
                // answer, and looping on it is a busy spin at full CPU until
                // the timeout. It is also the honest answer: with a single
                // execution context nothing can return a resource while this
                // caller waits, so saturation means THIS caller is holding
                // them — nested use() on an exhausted pool — and no amount of
                // waiting fixes that. Fail now, with the state, rather than in
                // five seconds with the same state.
                if (!$this->adapter->canBlock()) {
                    break;
                }

                // Empty-handed is NOT out of time: capacity may have been freed
                // by a caller that DISCARDED its resource rather than returning
                // one, and this acquisition can now create its own. Re-evaluate
                // against the SAME deadline — looping never extends the budget
                // the caller asked for.
                if (microtime(true) >= $deadline) {
                    break;
                }
            }

            throw new PoolTimeoutException(
                $this->name,
                $this->timeout,
                $this->size,
                \count($this->active),
                $this->adapter->count(),
            );
        } finally {
            $this->telemetry->histogram('pool.connection.wait_time', microtime(true) - $start, $this->labels);
        }
    }

    /**
     * Hand a connection back to the idle set without inspecting it.
     *
     * @param Connection<TResource> $connection
     */
    public function push(Connection $connection): static
    {
        $this->adapter->synchronized(function () use ($connection): void {
            $this->recordUse($connection->id);

            // A push can resume a parked borrower INLINE, and its track() must
            // not run until this checkout has left active ownership — hence the
            // order, and hence both inside one synchronized block.
            $this->adapter->push($connection);
            unset($this->active[$connection->id]);
        });

        $this->reportGauges();

        return $this;
    }

    /**
     * Return a resource after use, discarding it when the caller's work failed
     * and it cannot be recovered.
     *
     * The fallback chain exists because a lost slot is permanent: recover →
     * reclaim → destroy → forget. {@see forget()} is reached only when
     * `destroy()` itself threw, and its whole job is to make sure the slot comes
     * back regardless.
     *
     * @param Connection<TResource> $connection
     */
    public function release(Connection $connection, bool $failed = false): static
    {
        if (!$failed) {
            return $this->reclaim($connection);
        }

        if ($this->recover($connection)) {
            try {
                return $this->reclaim($connection);
            } catch (\Throwable) {
                try {
                    return $this->destroy($connection);
                } catch (\Throwable) {
                    return $this->forget($connection);
                }
            }
        }

        try {
            return $this->destroy($connection);
        } catch (\Throwable) {
            return $this->forget($connection);
        }
    }

    /**
     * @param Connection<TResource>|null $connection reclaims every active connection when null
     */
    public function reclaim(?Connection $connection = null): static
    {
        if ($connection instanceof Connection) {
            return $this->push($connection);
        }

        foreach (array_values($this->active) as $active) {
            $this->push($active);
        }

        return $this;
    }

    /**
     * Discard a connection and free its capacity.
     *
     * The replacement is created lazily by the next {@see pop()}, so destroying
     * never blocks on a connect and never fails for reasons unrelated to the
     * connection being discarded.
     *
     * @param Connection<TResource>|null $connection destroys every active connection when null
     */
    public function destroy(?Connection $connection = null): static
    {
        if (!$connection instanceof Connection) {
            foreach (array_values($this->active) as $active) {
                $this->destroy($active);
            }

            return $this;
        }

        $this->recordUse($connection->id);
        $this->closeResource($connection->resource);

        // Only release capacity this pool is actually holding. Destroying the
        // same connection twice, or one belonging to another pool, must not
        // drive $reserved below the truth and let the pool exceed its size.
        $this->adapter->synchronized(function () use ($connection): void {
            if (!isset($this->active[$connection->id])) {
                return;
            }

            unset($this->active[$connection->id]);
            $this->reserved--;
        });

        $this->adapter->unblock();
        $this->reportGauges();

        return $this;
    }

    /**
     * Ask every IDLE resource to run its own upkeep.
     *
     * A pooled resource nobody checks out is not a resource nothing happens to:
     * the server on the other end is still counting silence, and anything that
     * reaps an idle peer (`wait_timeout` on MySQL, eight hours by default) hands
     * the next caller a socket that is already gone — as a mid-request error
     * rather than a connect failure.
     *
     * The resource knows how to keep itself alive; what it lacks is a clock.
     * This package has no clock either, so a host that wants idle resources kept
     * alive calls this on an interval shorter than the shortest server-side
     * deadline it holds.
     *
     * Duck-typed on `ping()` for the same reason {@see recover()} probes for
     * `reset()`/`reconnect()`: this package depends on no driver, so the
     * capability is discovered rather than declared. Resources without one are
     * left alone, which is what makes this safe to call on every pool a host
     * owns.
     *
     * Resources are taken and returned ONE AT A TIME rather than under a single
     * lock, because a ping writes to the network and holding the pool's lock
     * across that would stall every pop() for the duration.
     */
    public function maintain(): static
    {
        /** @var list<Connection<TResource>> $taken */
        $taken = [];

        try {
            // Drain before pinging. Adapters choose their own order — Stack is
            // LIFO — so popping and pushing one at a time hands the same
            // resource straight back and leaves every other one unswept.
            for ($remaining = $this->adapter->count(); $remaining > 0; $remaining--) {
                $connection = $this->adapter->pop(0.0);

                if (!$connection instanceof Connection) {
                    break; // a concurrent pop() drained the idle set ahead of us
                }

                $taken[] = $connection;
            }

            // foreach iterates a snapshot, so dropping each entry as it is dealt
            // with leaves $taken holding exactly what the finally still owes.
            foreach ($taken as $index => $connection) {
                unset($taken[$index]);

                if (!$this->ping($connection->resource)) {
                    // A resource that cannot keep itself alive must not go back
                    // on the idle set.
                    $this->closeResource($connection->resource);
                    $this->releaseIdleSlot();

                    continue;
                }

                // Returned as soon as its own ping is done rather than at the
                // end, so the idle set refills while the sweep is still running.
                $this->adapter->push($connection);
            }
        } finally {
            // A resource stranded outside the pool is capacity lost for the life
            // of the process, so an interrupted sweep still hands back
            // everything it is holding.
            foreach ($taken as $connection) {
                $this->adapter->push($connection);
            }
        }

        $this->reportGauges();

        return $this;
    }

    /**
     * Close the pool: discard everything idle, and discard checked-out
     * resources as they come back.
     */
    public function close(): void
    {
        $this->closed = true;

        while (($connection = $this->adapter->pop(0.0)) instanceof Connection) {
            $this->closeResource($connection->resource);
            $this->reserved--;
        }

        $this->destroy();
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    /** Resources a caller could still obtain: idle, plus not yet created. */
    public function count(): int
    {
        return $this->adapter->count() + ($this->size - $this->reserved);
    }

    /** No resource can be obtained without one being returned first. */
    public function isEmpty(): bool
    {
        return $this->count() === 0;
    }

    /** Every resource the pool could hand out is available. */
    public function isFull(): bool
    {
        return $this->count() === $this->size;
    }

    /**
     * @return array{name: string, size: int, idle: int, active: int, reserved: int, available: int, closed: bool}
     */
    public function stats(): array
    {
        return [
            'name'      => $this->name,
            'size'      => $this->size,
            'idle'      => $this->adapter->count(),
            'active'    => \count($this->active),
            'reserved'  => $this->reserved,
            'available' => $this->count(),
            'closed'    => $this->closed,
        ];
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    /**
     * Create inside a slot that is already reserved.
     *
     * Split out so the reservation and its release are visibly one unit: the
     * slot exists before the resource does, and if `$init` throws, the `finally`
     * is the only thing standing between that and a permanently lost slot.
     *
     * @return Connection<TResource>
     */
    private function createInReservedSlot(float $requestedAt): Connection
    {
        $handedOver = false;

        try {
            $connection = new Connection($this->name . '-' . bin2hex(random_bytes(8)), ($this->init)(), $this);
            $this->track($connection, $requestedAt);
            $handedOver = true;

            return $connection;
        } finally {
            if (!$handedOver) {
                $this->adapter->synchronized(function (): void {
                    $this->reserved--;
                });
                $this->adapter->unblock();
            }
        }
    }

    /** @param Connection<TResource> $connection */
    private function track(Connection $connection, float $requestedAt): void
    {
        $this->adapter->synchronized(function () use ($connection, $requestedAt): void {
            $this->active[$connection->id]       = $connection;
            $this->checkedOutAt[$connection->id] = $requestedAt;
        });

        $this->reportGauges();
    }

    /** Record how long a connection was out, once, on whichever path returns it. */
    private function recordUse(string $id): void
    {
        if (!isset($this->checkedOutAt[$id])) {
            return;
        }

        $this->telemetry->histogram(
            'pool.connection.use_time',
            microtime(true) - $this->checkedOutAt[$id],
            $this->labels,
        );

        unset($this->checkedOutAt[$id]);
    }

    /**
     * Last-resort cleanup when destroy() itself failed: the connection must
     * never stay tracked as active, or its slot is lost to the pool forever.
     *
     * @param Connection<TResource> $connection
     */
    private function forget(Connection $connection): static
    {
        $untrack = function () use ($connection): void {
            if (!isset($this->active[$connection->id])) {
                return;
            }

            unset($this->active[$connection->id], $this->checkedOutAt[$connection->id]);
            $this->reserved--;
        };

        try {
            $this->adapter->synchronized($untrack);
        } catch (\Throwable) {
            // Even the lock failed. Untrack anyway: a slot lost for the life of
            // the process is worse than a bookkeeping write that raced.
            $untrack();
        }

        $this->adapter->unblock();

        return $this;
    }

    /**
     * Give back the capacity of a resource dropped from the IDLE set.
     *
     * {@see destroy()} cannot serve here: it releases capacity only for
     * connections in $active, and an idle one by definition is not. The resource
     * still existed though, so its slot is still counted in $reserved and has to
     * be given back or the pool shrinks by one every sweep.
     */
    private function releaseIdleSlot(): void
    {
        $this->adapter->synchronized(function (): void {
            $this->reserved--;
        });

        $this->adapter->unblock();
    }

    /**
     * Ask a resource to make itself reusable after a failure.
     *
     * Duck-typed: this package knows nothing about drivers, so the capability is
     * discovered. A resource with NEITHER method cannot be recovered — reported
     * as false so the caller discards rather than handing a possibly-broken
     * socket to the next borrower.
     *
     * @param Connection<TResource> $connection
     */
    private function recover(Connection $connection): bool
    {
        $resource = $connection->resource;

        if (!\is_object($resource)) {
            // A plain value cannot be broken by a caller's exception. A PHP
            // `resource` can, and there is no way to ask it.
            return !\is_resource($resource);
        }

        try {
            $recovered = false;

            foreach (['reset', 'reconnect'] as $method) {
                if (!method_exists($resource, $method)) {
                    continue;
                }

                $recovered = true;

                if ($resource->{$method}() === false) {
                    return false;
                }
            }

            return $recovered;
        } catch (\Throwable) {
            return false;
        }
    }

    /** Duck-typed liveness probe for {@see maintain()}. No `ping()` means "leave it alone". */
    private function ping(mixed $resource): bool
    {
        if (!\is_object($resource) || !method_exists($resource, 'ping')) {
            return true;
        }

        try {
            return $resource->ping() !== false;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Close a resource deterministically rather than waiting for the collector.
     *
     * Duck-typed on `close()`/`disconnect()`. Pooled sockets should be freed
     * promptly: leaving it to refcounting means a discarded connection's file
     * handle survives until the last reference anywhere drops, which under a
     * long-lived worker can be a long time.
     */
    private function closeResource(mixed $resource): void
    {
        if (!\is_object($resource)) {
            return;
        }

        foreach (['close', 'disconnect'] as $method) {
            if (!method_exists($resource, $method)) {
                continue;
            }

            try {
                $resource->{$method}();
            } catch (\Throwable) {
                // Nothing useful to do: the resource is being thrown away.
            }

            return;
        }
    }

    private function reportGauges(): void
    {
        if ($this->telemetry instanceof NullTelemetry) {
            return;
        }

        $this->telemetry->gauge('pool.connection.active.count', \count($this->active), $this->labels);
        $this->telemetry->gauge('pool.connection.idle.count', $this->adapter->count(), $this->labels);
        $this->telemetry->gauge('pool.connection.capacity.count', $this->reserved, $this->labels);
    }
}
