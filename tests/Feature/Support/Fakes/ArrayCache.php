<?php

declare(strict_types=1);

namespace Tests\Feature\Support\Fakes;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\{AbstractLock, CachePort, Lock};

/**
 * An in-memory CachePort, complete enough to boot a kernel and to exercise
 * anything that locks.
 *
 * Deliberately NOT a mock: a feature test asserts on outcomes, and a cache that
 * really stores and really expires is what makes "the second request was served
 * from cache" a true statement rather than a verified call count.
 */
final class ArrayCache implements CachePort
{
    /** @var array<string, array{value: mixed, expires: ?int}> */
    private array $store = [];

    /** @var array<string, string> lock name => owner token */
    public array $locks = [];

    /** Frozen clock, so TTL behaviour is testable without sleeping. */
    public int $now;

    public function __construct(?int $now = null)
    {
        $this->now = $now ?? time();
    }

    public function get(string $key): mixed
    {
        $entry = $this->store[$key] ?? null;

        if ($entry === null) {
            return null;
        }

        if ($entry['expires'] !== null && $entry['expires'] <= $this->now) {
            unset($this->store[$key]);

            return null;
        }

        return $entry['value'];
    }

    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        $this->store[$key] = ['value' => $value, 'expires' => $ttl === null ? null : $this->now + $ttl];

        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->store[$key]);

        return true;
    }

    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    public function remember(string $key, int $ttl, callable $callback): mixed
    {
        $hit = $this->get($key);

        if ($hit !== null) {
            return $hit;
        }

        $value = $callback();
        $this->set($key, $value, $ttl);

        return $value;
    }

    public function increment(string $key, int $by = 1): int
    {
        $value = (int) ($this->get($key) ?? 0) + $by;
        // Preserve the existing expiry: an incremented rate-limit counter whose
        // TTL resets on every hit never expires, which is the classic way a
        // throttle silently becomes permanent.
        $this->store[$key] = ['value' => $value, 'expires' => $this->store[$key]['expires'] ?? null];

        return $value;
    }

    public function deletePattern(string $pattern): int
    {
        $regex   = '#^' . str_replace('\*', '.*', preg_quote($pattern, '#')) . '$#';
        $deleted = 0;

        foreach (array_keys($this->store) as $key) {
            if (preg_match($regex, $key) === 1) {
                unset($this->store[$key]);
                $deleted++;
            }
        }

        return $deleted;
    }

    public function flush(): bool
    {
        $this->store = [];
        $this->locks = [];

        return true;
    }

    public function lock(string $name, int $seconds = 0, ?string $owner = null): Lock
    {
        return new ArrayLock($this, $name, $seconds, $owner ?? bin2hex(random_bytes(8)));
    }

    public function restoreLock(string $name, string $owner): Lock
    {
        return new ArrayLock($this, $name, 0, $owner);
    }

    /** @return array<string, mixed> everything currently stored, for assertions */
    public function all(): array
    {
        return array_map(static fn(array $e): mixed => $e['value'], $this->store);
    }
}

/**
 * The lock ArrayCache hands out.
 *
 * Extends AbstractLock rather than implementing Lock, because block()'s timeout
 * semantics are part of the CONTRACT — a fake that reimplements them would let a
 * test pass against behaviour no real adapter has.
 */
final class ArrayLock extends AbstractLock
{
    public function __construct(
        private readonly ArrayCache $cache,
        string $name,
        int $seconds,
        string $owner,
    ) {
        parent::__construct($name, $seconds, $owner);
    }

    public function acquire(): bool
    {
        if (isset($this->cache->locks[$this->name])) {
            return false;
        }

        $this->cache->locks[$this->name] = $this->owner;

        return true;
    }

    public function release(): bool
    {
        // Only the owner may release — releasing someone else's lock is the bug
        // this check exists to catch, and a fake that allows it hides it.
        if (($this->cache->locks[$this->name] ?? null) !== $this->owner) {
            return false;
        }

        unset($this->cache->locks[$this->name]);

        return true;
    }

    public function forceRelease(): void
    {
        unset($this->cache->locks[$this->name]);
    }
}
