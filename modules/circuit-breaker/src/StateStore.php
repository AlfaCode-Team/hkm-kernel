<?php

declare(strict_types=1);

namespace AlfacodeTeam\CircuitBreaker;

/**
 * Where a circuit's state lives.
 *
 * WHY THIS INTERFACE EXISTS RATHER THAN A CACHE DEPENDENCY
 * -------------------------------------------------------
 * A breaker is only useful if every process shares its verdict. One PHP-FPM
 * worker discovering that a payment gateway is down is worth nothing if the
 * other thirty-one each have to discover it for themselves — that is thirty-one
 * more requests hanging on a socket timeout, which is the failure the breaker
 * was installed to prevent.
 *
 * So state must be shared, which in practice means Redis. But this package
 * cannot depend on a cache library without becoming unusable anywhere else, and
 * cannot depend on a framework port without becoming a framework package. It
 * declares the three operations it actually needs and lets the consumer supply
 * them — the same reason a migration engine declares a PDO requirement rather
 * than an ORM.
 *
 * ATOMICITY IS PART OF THE CONTRACT. {@see self::increment()} must be atomic
 * across processes. If two workers can each read 4, add one, and write 5, then a
 * breaker with a threshold of 5 never trips under exactly the concurrent load
 * that matters. Redis INCR, APCu apcu_inc and a locked read-modify-write all
 * satisfy this; `get()` followed by `set()` does not.
 */
interface StateStore
{
    /**
     * Atomically add one to a counter and return the new value.
     *
     * Creates the key at 1 when absent. $ttl is applied when the key is
     * created; an implementation MUST NOT extend the TTL of an existing key, or
     * a steady trickle of failures keeps the window alive forever and the
     * "failures within a window" semantics become "failures ever".
     */
    public function increment(string $key, int $ttl): int;

    /** The stored string, or null when absent or expired. */
    public function get(string $key): ?string;

    /** Store a string with a TTL in seconds. */
    public function set(string $key, string $value, int $ttl): void;

    /** Remove a key. A no-op when it does not exist. */
    public function delete(string $key): void;
}
