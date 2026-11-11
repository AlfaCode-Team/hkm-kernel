<?php

declare(strict_types=1);

namespace AlfacodeTeam\Pools\Adapter;

use AlfacodeTeam\Pools\Adapter;

/**
 * The single-execution-context adapter: a LIFO array. The correct choice under
 * PHP-FPM and in tests.
 *
 * `pop()` ignores its timeout and `synchronized()` takes no lock, and neither is
 * a shortcut. With one execution context nothing can hand a resource back while
 * the caller waits, so waiting cannot change the answer, and nothing can
 * interleave with the callback, so there is nothing to serialise. Taking a real
 * lock here would be a lock that can never be contended.
 *
 * LIFO rather than FIFO on purpose: the most recently returned resource is the
 * one most likely to still have a live socket and warm server-side caches, and
 * reusing it lets the rest age out through `maxLifetime` instead of keeping the
 * whole set marginally warm.
 */
final class Stack extends Adapter
{
    /** @var list<mixed> */
    private array $idle = [];

    public function initialize(int $size): static
    {
        $this->idle = [];

        return $this;
    }

    public function push(mixed $connection): static
    {
        $this->idle[] = $connection;

        return $this;
    }

    /** @param float $timeout ignored — see the class docblock */
    public function pop(float $timeout): mixed
    {
        return array_pop($this->idle);
    }

    public function count(): int
    {
        return \count($this->idle);
    }

    public function synchronized(callable $callback): mixed
    {
        return $callback();
    }
}
