<?php

declare(strict_types=1);

namespace AlfacodeTeam\Pools;

use AlfacodeTeam\Pools\Exception\UnknownPoolException;

/**
 * Named pools, addressed as one thing.
 *
 * What a connection manager actually holds: `central`, `tenant:acme`,
 * `tenant:globex`, `redis`, each with its own size and factory. The group
 * exists so the lifecycle operations — reclaim everything, close everything,
 * maintain everything — are one call rather than a loop every caller writes
 * for itself and one of them forgets.
 *
 * ─── IT IS BOUNDED, AND THAT IS THE POINT ───────────────────────────────────
 *
 * A multi-tenant deployment creates a pool per tenant on demand, and a
 * long-lived worker that has ever served N tenants would otherwise hold N
 * pools' worth of sockets forever — even when only a handful are ever active at
 * once. `$maxPools` caps that, closing the least recently used pool when a new
 * one needs room. This is the same bound `TENANCY_MAX_WARM_CONNECTIONS` applies
 * by hand in the tenancy plugin; having it here means every consumer gets it
 * rather than every consumer reimplementing it.
 *
 * 0 means unbounded, for a deployment with a fixed, known set of pools.
 */
final class Group
{
    /**
     * Insertion-ordered, most recently used LAST — so the first key is the
     * eviction candidate.
     *
     * @var array<string, Pool<mixed>>
     */
    private array $pools = [];

    public function __construct(private readonly int $maxPools = 0)
    {
        if ($maxPools < 0) {
            throw new \InvalidArgumentException("Group maxPools cannot be negative, got {$maxPools}.");
        }
    }

    /**
     * Register a pool, evicting the least recently used one if there is no room.
     *
     * @param Pool<mixed> $pool
     */
    public function add(Pool $pool): static
    {
        // Replacing an existing name is not a growth, so the cap is only
        // consulted for a genuinely new pool.
        if (!isset($this->pools[$pool->name])) {
            $this->evictToMake(1);
        }

        unset($this->pools[$pool->name]);
        $this->pools[$pool->name] = $pool;

        return $this;
    }

    public function has(string $name): bool
    {
        return isset($this->pools[$name]);
    }

    /**
     * @return Pool<mixed>
     * @throws UnknownPoolException
     */
    public function get(string $name): Pool
    {
        $pool = $this->pools[$name] ?? throw UnknownPoolException::named($name, array_keys($this->pools));

        // Touch: this pool is now the most recently used, so it is the last to
        // be evicted. Without this the cap would evict by CREATION order and
        // throw away the pool serving most of the traffic.
        unset($this->pools[$name]);
        $this->pools[$name] = $pool;

        return $pool;
    }

    /**
     * The pool under $name, created by $factory if it is not registered yet.
     *
     * The shape a per-tenant manager needs: ask for `tenant:acme`, get it
     * whether or not this worker has seen that tenant before, with the bound
     * applied for free.
     *
     * @param  callable(): Pool<mixed> $factory
     * @return Pool<mixed>
     */
    public function remember(string $name, callable $factory): Pool
    {
        if ($this->has($name)) {
            return $this->get($name);
        }

        $pool = $factory();

        if ($pool->name !== $name) {
            throw new \InvalidArgumentException(
                "Group::remember('{$name}') was given a pool named [{$pool->name}]. The factory must "
                . 'name the pool exactly as it is addressed, or get() and remove() would miss it.',
            );
        }

        $this->add($pool);

        return $pool;
    }

    /** Close and forget one pool. Unknown names are ignored. */
    public function remove(string $name): static
    {
        $pool = $this->pools[$name] ?? null;

        if ($pool === null) {
            return $this;
        }

        unset($this->pools[$name]);
        $pool->close();

        return $this;
    }

    /** @return list<string> most recently used last */
    public function names(): array
    {
        return array_keys($this->pools);
    }

    public function count(): int
    {
        return \count($this->pools);
    }

    /**
     * Run $callback with one borrowed resource from each named pool.
     *
     * Nested {@see Pool::use()} calls, so every resource is returned however
     * the callback or a later acquisition fails — which is the entire reason to
     * have this rather than acquiring in a loop. The callback receives the
     * resources positionally, in the order named.
     *
     * @template T
     * @param  list<string>        $names
     * @param  callable(mixed ...): T $callback
     * @return T
     */
    public function use(array $names, callable $callback): mixed
    {
        /** @var list<mixed> $resources */
        $resources = [];

        $next = function () use (&$next, &$resources, $names, $callback): mixed {
            $index = \count($resources);

            if ($index === \count($names)) {
                return $callback(...$resources);
            }

            return $this->get($names[$index])->use(function (mixed $resource) use (&$next, &$resources): mixed {
                $resources[] = $resource;

                try {
                    return $next();
                } finally {
                    array_pop($resources);
                }
            });
        };

        return $next();
    }

    /** Return every checked-out resource in every pool. */
    public function reclaim(): static
    {
        foreach ($this->pools as $pool) {
            $pool->reclaim();
        }

        return $this;
    }

    /** Run every pool's idle-resource upkeep — see {@see Pool::maintain()}. */
    public function maintain(): static
    {
        foreach ($this->pools as $pool) {
            $pool->maintain();
        }

        return $this;
    }

    public function close(): void
    {
        foreach (array_keys($this->pools) as $name) {
            $this->remove($name);
        }
    }

    /** @return list<array{name: string, size: int, idle: int, active: int, reserved: int, available: int, closed: bool}> */
    public function stats(): array
    {
        return array_values(array_map(static fn (Pool $p): array => $p->stats(), $this->pools));
    }

    /** Close least-recently-used pools until $room more will fit under the cap. */
    private function evictToMake(int $room): void
    {
        if ($this->maxPools === 0) {
            return;
        }

        while (\count($this->pools) + $room > $this->maxPools) {
            $oldest = array_key_first($this->pools);

            if ($oldest === null) {
                return;
            }

            $this->remove($oldest);
        }
    }
}
