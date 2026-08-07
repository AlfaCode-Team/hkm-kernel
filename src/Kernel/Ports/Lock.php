<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Ports;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\LockTimeoutException;

/**
 * Lock — a mutually-exclusive, TTL-bounded, owner-checked lock.
 *
 * Obtained from {@see CachePort::lock()}. The kernel defines the contract; every
 * implementation ships in a plugin or the project's infrastructure layer.
 *
 * WHY THIS EXISTS
 * ---------------
 * The platform targets persistent multi-worker runtimes (OpenSwoole) and ships a
 * Worker pipeline. Without an atomic lock there is no correct way to express:
 *
 *   - single-flight scheduled work (two workers firing the same cron)
 *   - job idempotency (the same payload processed twice on retry)
 *   - any check-then-act across workers ("if (!exists) create")
 *   - cache stampede protection (N concurrent misses all running the callback)
 *
 * CachePort::increment() is atomic but is NOT a lock: it cannot express
 * ownership, blocking acquisition, TTL-bounded release, or safe re-entry.
 *
 * OWNERSHIP IS THE POINT
 * ----------------------
 * Every lock carries an owner token. release() only succeeds for the holder, so
 * a process whose lock already expired cannot delete the lock a DIFFERENT
 * process has since acquired. Implementations MUST make the check-and-delete
 * atomic (a Lua script on Redis) — a GET followed by a DEL races and is wrong.
 *
 * Use forceRelease() only for administrative recovery, never on the hot path.
 *
 * TYPICAL USE
 * -----------
 *   $lock = $cache->lock('report:nightly', 300);
 *   if (!$lock->acquire()) {
 *       return;                       // another worker owns it — do nothing
 *   }
 *   try {
 *       $this->generate();
 *   } finally {
 *       $lock->release();
 *   }
 *
 * Or let the lock manage its own lifecycle, blocking up to 10s to acquire:
 *
 *   $result = $cache->lock('report:nightly', 300)
 *                   ->block(10, fn() => $this->generate());
 */
interface Lock
{
    /**
     * Try to acquire ONCE without waiting.
     *
     * @return bool true when this caller now holds the lock.
     */
    public function acquire(): bool;

    /**
     * Wait up to $seconds to acquire.
     *
     * With a callback: runs it while holding the lock, ALWAYS releases
     * afterwards (even if the callback throws) and returns the callback's value.
     * Without a callback: returns true once acquired — the caller then owns the
     * release.
     *
     * Implementations MUST sleep coroutine-aware (Coroutine::usleep under
     * OpenSwoole) so waiting never blocks the whole worker.
     *
     * @throws LockTimeoutException when the lock could not be acquired in time.
     */
    public function block(int $seconds, ?callable $callback = null): mixed;

    /**
     * Release, but ONLY if this instance still owns it.
     *
     * @return bool false when the lock had expired or is held by someone else.
     */
    public function release(): bool;

    /** This instance's owner token — pass to CachePort::restoreLock() to reattach. */
    public function owner(): string;

    /** Delete the lock regardless of owner. Administrative recovery only. */
    public function forceRelease(): void;
}
