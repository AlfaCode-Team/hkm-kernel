<?php

declare(strict_types=1);

namespace AlfacodeTeam\CircuitBreaker\Store;

use AlfacodeTeam\CircuitBreaker\StateStore;

/**
 * A StateStore held in process memory.
 *
 * FOR TESTS, AND FOR ONE DEPLOYMENT SHAPE
 * ---------------------------------------
 * Primarily this is what makes the breaker testable without Redis. It is also
 * correct for a single long-lived process — a queue worker, a CLI task — where
 * "shared across processes" is a distinction without a difference.
 *
 * It is NOT suitable for PHP-FPM. Memory dies with the request, so each worker
 * would have to rediscover an outage independently, which is exactly the
 * thundering timeout the breaker exists to prevent. Under FPM, back the breaker
 * with Redis.
 */
final class InMemoryStateStore implements StateStore
{
    /** @var array<string, array{value: string, expires: int}> */
    private array $entries = [];

    /** @var (callable(): int)|null */
    private readonly mixed $clock;

    /** @param (callable(): int)|null $clock unix seconds; defaults to time(). */
    public function __construct(?callable $clock = null)
    {
        $this->clock = $clock;
    }

    public function increment(string $key, int $ttl): int
    {
        $current = $this->get($key);

        if ($current === null) {
            $this->set($key, '1', $ttl);

            return 1;
        }

        $next = ((int) $current) + 1;

        // Preserve the existing expiry rather than refreshing it — the contract
        // in StateStore::increment(). Refreshing would turn a rolling window
        // into one a steady trickle of failures keeps alive indefinitely.
        $this->entries[$key]['value'] = (string) $next;

        return $next;
    }

    public function get(string $key): ?string
    {
        $entry = $this->entries[$key] ?? null;

        if ($entry === null) {
            return null;
        }

        if ($entry['expires'] <= $this->now()) {
            unset($this->entries[$key]);

            return null;
        }

        return $entry['value'];
    }

    public function set(string $key, string $value, int $ttl): void
    {
        $this->entries[$key] = [
            'value' => $value,
            'expires' => $this->now() + max(1, $ttl),
        ];
    }

    public function delete(string $key): void
    {
        unset($this->entries[$key]);
    }

    private function now(): int
    {
        return $this->clock === null ? time() : (int) ($this->clock)();
    }
}
