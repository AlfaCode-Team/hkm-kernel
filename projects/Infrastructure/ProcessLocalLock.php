<?php

declare(strict_types=1);

namespace Project\Infrastructure;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\AbstractLock;

/**
 * ProcessLocalLock — a lock that is ONLY valid inside one PHP process.
 *
 * ⚠️  THIS IS NOT A DISTRIBUTED LOCK. ⚠️
 *
 * It pairs with {@see InMemoryCache}, whose state is per-process, so the lock can
 * be no stronger than the store behind it. Under PHP-FPM every request is a
 * different process, so two concurrent requests BOTH acquire "the same" lock and
 * both enter the critical section. Under OpenSwoole it holds within one worker
 * and fails across workers.
 *
 * It is included because a CachePort must implement the whole contract, and
 * because it is genuinely correct in the one place it is used: single-process
 * test and CLI runs. It is documented this loudly because a lock that silently
 * only works in one process is more dangerous than no lock at all — the code
 * looks protected, passes tests, and races only in production.
 *
 * For anything real use {@see FileLock} (cross-process, single machine) or the
 * RedisCache plugin's RedisLock (cross-machine).
 *
 * Within a single process this IS correct: PHP has no preemptive threads, and
 * coroutines only switch at await points, of which acquire() has none.
 */
final class ProcessLocalLock extends AbstractLock
{
    /**
     * @param object{locks: array<string, array{owner: string, expires: int}>} $registry
     *        the owning cache instance, so every lock it hands out shares one table
     */
    public function __construct(
        private readonly object $registry,
        string $name,
        int $seconds,
        ?string $owner = null,
    ) {
        parent::__construct($name, $seconds, $owner ?? self::randomOwner());
    }

    public function acquire(): bool
    {
        $current = $this->registry->locks[$this->name] ?? null;

        if ($current !== null && !$this->isExpired($current)) {
            return false;
        }

        $this->registry->locks[$this->name] = [
            'owner'   => $this->owner,
            'expires' => $this->seconds > 0 ? \time() + $this->seconds : 0,
        ];

        return true;
    }

    public function release(): bool
    {
        $current = $this->registry->locks[$this->name] ?? null;

        if ($current === null || !\hash_equals($this->owner, $current['owner'])) {
            return false;
        }

        unset($this->registry->locks[$this->name]);

        return true;
    }

    public function forceRelease(): void
    {
        unset($this->registry->locks[$this->name]);
    }

    /** @param array{owner: string, expires: int} $record */
    private function isExpired(array $record): bool
    {
        return $record['expires'] > 0 && $record['expires'] < \time();
    }
}
