<?php

declare(strict_types=1);

namespace AlfacodeTeam\Pools;

/**
 * Storage and synchronisation for a pool's IDLE resources.
 *
 * WHY THE POOL DOES NOT DO THIS ITSELF
 * ------------------------------------
 * A pool has to behave correctly in two runtimes that are nothing alike. Under
 * PHP-FPM one request owns the process: nothing can hand a resource back while
 * the caller waits, so waiting is pointless and a plain array is the whole
 * answer. Under OpenSwoole many coroutines share the worker: waiting is the
 * normal case, and the idle set has to be a channel that parks the caller
 * instead of spinning.
 *
 * Without this seam those two behaviours end up as `class_exists('\Swoole…')`
 * checks scattered through the pool — which is how the predecessor to this
 * package was written, and it meant the FPM path carried a spin-sleep loop it
 * could never benefit from while the coroutine path was never actually tested
 * against a channel.
 *
 * The split of responsibility is strict:
 *
 *   - the POOL owns capacity accounting (how many resources may exist);
 *   - the ADAPTER owns only what is idle, and serialises access to the pool's
 *     bookkeeping.
 *
 * So `$size` here is a sizing hint, not a cap to police.
 */
abstract class Adapter
{
    /** Prepare to hold up to $size idle resources. */
    abstract public function initialize(int $size): static;

    abstract public function push(mixed $connection): static;

    /**
     * Take an idle resource, waiting up to $timeout seconds for one to arrive.
     *
     * Returns the resource, or `null`/`false` when none was handed over. An
     * empty-handed return is NOT the same as "out of time": capacity may have
     * been freed without a resource coming back, and the pool re-reads the real
     * state either way.
     */
    abstract public function pop(float $timeout): mixed;

    abstract public function count(): int;

    /**
     * Can {@see pop()} actually WAIT, or does it always answer immediately?
     *
     * The pool needs this to avoid a busy spin. Its acquire loop is written to
     * re-check after an empty-handed pop, because capacity can be freed without
     * a resource coming back — but with an adapter that cannot block, that loop
     * has nothing to wait for and would spin at full CPU until the timeout.
     *
     * It is also the honest answer for the single-context case: if nothing can
     * run while the caller waits, saturation means the caller itself is holding
     * the resources, and no amount of waiting will change that. Failing
     * immediately with the pool's state is more useful than failing five
     * seconds later with the same state.
     */
    public function canBlock(): bool
    {
        return false;
    }

    /**
     * Release anyone currently parked in {@see pop()} without handing over a
     * resource — "it is worth looking again".
     *
     * An adapter with no way to block satisfies this by construction: a caller
     * that never waits has nothing to release.
     */
    public function unblock(): static
    {
        return $this;
    }

    /**
     * Run $callback atomically with respect to other pool operations.
     *
     * An adapter without concurrency satisfies this by construction and calls
     * $callback directly.
     */
    abstract public function synchronized(callable $callback): mixed;
}
