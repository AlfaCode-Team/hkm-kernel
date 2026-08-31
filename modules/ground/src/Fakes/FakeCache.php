<?php

declare(strict_types=1);

namespace AlfacodeTeam\Ground\Fakes;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\AbstractLock;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\CachePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Lock;

/**
 * An in-memory CachePort with a clock the test controls.
 *
 * TTLs are evaluated against {@see FrozenClock} rather than real time, so a test
 * for "the OTP expires after five minutes" advances the clock instead of
 * sleeping. Nothing in here survives the process — which is correct for a test
 * double, and exactly why the production default is FileCache: a per-process
 * cache looks identical to an expired entry the moment a second request reads it.
 */
final class FakeCache implements CachePort
{
    /** @var array<string, array{value: mixed, expires: ?int}> */
    private array $store = [];

    /** @var array<string, string> lock name => owner token */
    private array $locks = [];

    /** @var list<array{op: string, key: string}> */
    public array $operations = [];

    public function __construct(
        private readonly FrozenClock $clock = new FrozenClock(),
    ) {}

    public function clock(): FrozenClock
    {
        return $this->clock;
    }

    public function get(string $key): mixed
    {
        $this->operations[] = ['op' => 'get', 'key' => $key];

        return $this->live($key) ? $this->store[$key]['value'] : null;
    }

    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        $this->operations[] = ['op' => 'set', 'key' => $key];
        $this->store[$key]  = [
            'value'   => $value,
            'expires' => $ttl === null || $ttl <= 0 ? null : $this->clock->timestamp() + $ttl,
        ];

        return true;
    }

    public function delete(string $key): bool
    {
        $this->operations[] = ['op' => 'delete', 'key' => $key];
        unset($this->store[$key]);

        return true;
    }

    public function has(string $key): bool
    {
        return $this->live($key);
    }

    public function remember(string $key, int $ttl, callable $callback): mixed
    {
        if ($this->live($key)) {
            return $this->store[$key]['value'];
        }

        $value = $callback();
        $this->set($key, $value, $ttl);

        return $value;
    }

    public function increment(string $key, int $by = 1): int
    {
        $current = $this->live($key) ? (int) $this->store[$key]['value'] : 0;
        $next    = $current + $by;

        // Preserve the existing expiry — a rate-limit counter that reset its own
        // window on every hit would never actually limit anything.
        $expires = $this->store[$key]['expires'] ?? null;
        $this->store[$key] = ['value' => $next, 'expires' => $expires];
        $this->operations[] = ['op' => 'increment', 'key' => $key];

        return $next;
    }

    public function deletePattern(string $pattern): int
    {
        $regex   = '/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/';
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
        return new FakeLock($this, $name, $seconds, $owner ?? bin2hex(random_bytes(16)));
    }

    public function restoreLock(string $name, string $owner): Lock
    {
        return new FakeLock($this, $name, 0, $owner);
    }

    // ── Asserting ─────────────────────────────────────────────────────────────

    /** Every key currently live, for a readable failure message. */
    public function keys(): array
    {
        return array_values(array_filter(array_keys($this->store), fn(string $k): bool => $this->live($k)));
    }

    /** Read a value WITHOUT recording an operation — for assertions. */
    public function peek(string $key): mixed
    {
        return $this->live($key) ? $this->store[$key]['value'] : null;
    }

    // ── Lock internals, reached only by FakeLock ──────────────────────────────

    /** @internal */
    public function acquireLock(string $name, string $owner): bool
    {
        if (isset($this->locks[$name])) {
            return false;
        }
        $this->locks[$name] = $owner;

        return true;
    }

    /** @internal */
    public function releaseLock(string $name, string $owner): bool
    {
        // Ownership check, not a courtesy: releasing someone else's lock is the
        // failure mode owner tokens exist to prevent, and a double that ignored
        // it would let that bug pass its test.
        if (($this->locks[$name] ?? null) !== $owner) {
            return false;
        }
        unset($this->locks[$name]);

        return true;
    }

    /** @internal */
    public function forceReleaseLock(string $name): void
    {
        unset($this->locks[$name]);
    }

    private function live(string $key): bool
    {
        if (!isset($this->store[$key])) {
            return false;
        }

        $expires = $this->store[$key]['expires'];
        if ($expires !== null && $expires <= $this->clock->timestamp()) {
            unset($this->store[$key]);

            return false;
        }

        return true;
    }
}
