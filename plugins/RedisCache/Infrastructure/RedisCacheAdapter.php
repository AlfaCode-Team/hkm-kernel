<?php

declare(strict_types=1);

namespace Plugins\RedisCache\Infrastructure;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\CachePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Lock;

/**
 * Redis-backed CachePort adapter (GDA rewrite of the 0.3 Redis cache layer).
 *
 * Numeric values are stored RAW so Redis' native incrBy works and set()/get()/
 * increment() interoperate (the kernel rate limiter does set(1) then increment);
 * every other value is PHP-serialized so any payload round-trips. This mirrors
 * the in-memory CachePort contract exactly. Native TTL handles expiry.
 * deletePattern() uses non-blocking SCAN (never KEYS) so it is safe on large
 * keyspaces. When Redis is not configured the base bootstrap binds the in-memory
 * CachePort instead — this adapter is only wired when REDIS_HOST is set.
 */
final class RedisCacheAdapter implements CachePort
{
    public function __construct(private readonly RedisConnection $connection) {}

    public function get(string $key): mixed
    {
        $raw = $this->connection->client()->get($this->connection->prefix($key));
        if ($raw === false) {
            return null;
        }
        return $this->unserialize($raw);
    }

    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        $key  = $this->connection->prefix($key);
        $data = $this->serialize($value);
        $client = $this->connection->client();

        return $ttl === null || $ttl <= 0
            ? (bool) $client->set($key, $data)
            : (bool) $client->setex($key, $ttl, $data);
    }

    /**
     * Store numbers raw (so incrBy/decrBy operate on them), everything else
     * PHP-serialized. Matches Laravel's RedisStore numeric handling.
     */
    private function serialize(mixed $value): string
    {
        return is_int($value) || (is_float($value) && is_finite($value))
            ? (string) $value
            : serialize($value);
    }

    private function unserialize(string $raw): mixed
    {
        // A raw integer/float string was stored by set()/incrBy — return it typed.
        if (preg_match('/^-?\d+$/', $raw) === 1) {
            return (int) $raw;
        }
        if (is_numeric($raw)) {
            return (float) $raw;
        }
        return @unserialize($raw, ['allowed_classes' => true]);
    }

    public function delete(string $key): bool
    {
        return $this->connection->client()->del($this->connection->prefix($key)) > 0;
    }

    public function has(string $key): bool
    {
        return (bool) $this->connection->client()->exists($this->connection->prefix($key));
    }

    public function remember(string $key, int $ttl, callable $callback): mixed
    {
        if ($this->has($key)) {
            return $this->get($key);
        }
        $value = $callback();
        $this->set($key, $value, $ttl);
        return $value;
    }

    public function increment(string $key, int $by = 1): int
    {
        return (int) $this->connection->client()->incrBy($this->connection->prefix($key), $by);
    }

    public function deletePattern(string $pattern): int
    {
        $client = $this->connection->client();
        $match  = $this->connection->prefix($pattern);
        $deleted = 0;
        $iterator = null;

        // SCAN returns batches until the iterator returns to 0.
        do {
            $keys = $client->scan($iterator, $match, 200);
            if ($keys !== false && $keys !== []) {
                $deleted += (int) $client->del($keys);
            }
        } while ($iterator > 0);

        return $deleted;
    }

    public function flush(): bool
    {
        // Only flush keys under our prefix when one is set; otherwise flush the db.
        if ($this->connection->rawPrefix() === '') {
            return (bool) $this->connection->client()->flushDB();
        }
        $this->deletePattern('*');
        return true;
    }

    public function lock(string $name, int $seconds = 0, ?string $owner = null): Lock
    {
        return new RedisLock($this->connection, $name, $seconds, $owner);
    }

    public function restoreLock(string $name, string $owner): Lock
    {
        return new RedisLock($this->connection, $name, 0, $owner);
    }
}
