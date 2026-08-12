<?php
declare(strict_types=1);
namespace AlfacodeTeam\PhpServicePlatform\Kernel\Ports;
interface CachePort
{
    public function get(string $key): mixed;
    public function set(string $key, mixed $value, ?int $ttl = null): bool;
    public function delete(string $key): bool;
    public function has(string $key): bool;
    public function remember(string $key, int $ttl, callable $callback): mixed;
    public function increment(string $key, int $by = 1): int;
    public function deletePattern(string $pattern): int;
    public function flush(): bool;

    /**
     * A mutually-exclusive, TTL-bounded lock. See {@see Lock} for the contract
     * and for why increment() is not a substitute.
     *
     * @param string      $name    logical lock name (the adapter applies its own prefix)
     * @param int         $seconds TTL — how long the lock survives if never released.
     *                             0 means "until released", which risks a permanent
     *                             lock if the holder dies; always prefer a TTL.
     * @param string|null $owner   explicit owner token; a random one is generated
     *                             when null. Pass one only to reattach deliberately.
     */
    public function lock(string $name, int $seconds = 0, ?string $owner = null): Lock;

    /**
     * Reattach to a lock acquired elsewhere (another process, an earlier request)
     * using its owner token, so THIS caller can release it. Does not acquire.
     */
    public function restoreLock(string $name, string $owner): Lock;
}
