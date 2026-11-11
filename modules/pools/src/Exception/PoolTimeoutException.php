<?php

declare(strict_types=1);

namespace AlfacodeTeam\Pools\Exception;

/**
 * No resource became available within the pool's timeout.
 *
 * Carries the pool's state at the moment it gave up, because "timed out
 * acquiring a connection" and "timed out with 20 of 20 checked out for longer
 * than the budget" call for completely different fixes — the first reads as a
 * slow database, the second as a leak or an under-sized pool.
 */
final class PoolTimeoutException extends \RuntimeException
{
    public function __construct(
        public readonly string $pool,
        public readonly float $timeout,
        public readonly int $size,
        public readonly int $active,
        public readonly int $idle,
    ) {
        parent::__construct(sprintf(
            'Pool [%s] could not provide a resource within %ss (size %d, active %d, idle %d).',
            $pool,
            $timeout,
            $size,
            $active,
            $idle,
        ));
    }
}
