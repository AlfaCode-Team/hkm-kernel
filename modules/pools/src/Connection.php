<?php

declare(strict_types=1);

namespace AlfacodeTeam\Pools;

use WeakReference;

/**
 * A resource checked out of a pool.
 *
 * Immutable: identity, resource and owning pool are fixed at construction, so a
 * connection held by one coroutine cannot be repointed at another pool or have
 * its resource swapped underneath it.
 *
 * ─── WHY THE POOL REFERENCE IS WEAK ─────────────────────────────────────────
 *
 * A strong one is a cycle. The pool reaches its idle connections through its
 * adapter, and with the coroutine adapter that path runs through a
 * `Coroutine\Channel` whose contents live in C memory. PHP's cycle collector
 * cannot traverse a channel, so it could never prove such a cycle unreachable,
 * and the channel's own reference keeps the refcounts above zero — every pool,
 * every connection and every pooled resource would stay alive for the life of
 * the process. On a multi-tenant deployment that is one leaked socket per
 * tenant ever served, which is precisely the failure
 * `TENANCY_MAX_WARM_CONNECTIONS` exists to work around at a different layer.
 *
 * Holding the pool weakly keeps the object graph acyclic, so dropping a pool
 * releases its connections and their resources by refcount alone.
 *
 * Reclaiming or destroying a connection whose pool is already gone is a no-op:
 * the pool that owned the capacity no longer exists to account for it.
 *
 * @template TResource
 */
final readonly class Connection
{
    /** @var WeakReference<Pool<TResource>> */
    private WeakReference $pool;

    /**
     * @param TResource     $resource
     * @param Pool<TResource> $pool
     */
    public function __construct(
        public string $id,
        public mixed $resource,
        Pool $pool,
    ) {
        $this->pool = WeakReference::create($pool);
    }

    /** Return this connection to its pool for reuse. */
    public function reclaim(): void
    {
        $this->pool->get()?->reclaim($this);
    }

    /** Discard this connection and free its capacity for a replacement. */
    public function destroy(): void
    {
        $this->pool->get()?->destroy($this);
    }

    /** Whether the owning pool is still alive. */
    public function isOrphaned(): bool
    {
        return $this->pool->get() === null;
    }
}
